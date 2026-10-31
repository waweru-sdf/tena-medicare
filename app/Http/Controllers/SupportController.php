<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Audit;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Ticket;
use App\Services\Billing;
use App\Services\Notifier;
use App\Services\Present;
use Illuminate\Http\Request;

/** Support and complaint tickets, and the finance screens (payouts, refunds, reports). */
class SupportController extends Controller
{
    private const KINDS = ['support' => ['support'], 'compliance' => ['complaint', 'data_request']];

    private function visible(Request $r)
    {
        $u = $this->who($r);
        $q = Ticket::with('user');
        if (isset(self::KINDS[$u->role])) {
            $q->whereIn('kind', self::KINDS[$u->role]);
        } elseif (in_array($u->role, ['patient', 'clinician'], true)) {
            $q->where('user_id', $u->id);
        }
        return $q;
    }

    public function create(Request $r)
    {
        $d = $this->body($r);
        if (!in_array($d['kind'] ?? '', ['support', 'complaint', 'data_request'], true) || trim((string) ($d['subject'] ?? '')) === '') {
            return $this->err('Choose a type and add a subject.');
        }
        Ticket::create([
            'user_id' => $this->who($r)->id, 'kind' => $d['kind'], 'subject' => mb_substr(trim((string) $d['subject']), 0, 150), 'body' => mb_substr((string) ($d['body'] ?? ''), 0, 2000),
            'status' => 'open', 'due_at' => $d['kind'] === 'data_request' ? now()->addDays(30) : null, 'created' => now(),   // confirm the legal deadline with your adviser
        ]);
        Audit::record('ticket_' . $d['kind']);
        return response()->json(['ok' => true]);
    }

    public function index(Request $r)
    {
        return response()->json($this->visible($r)->orderByDesc('id')->limit(100)->get()->map(fn ($t) => [
            'id' => $t->id, 'kind' => $t->kind, 'subject' => $t->subject, 'body' => $t->body, 'status' => $t->status, 'resolution' => $t->resolution,
            'who' => $t->user->name, 'due_at' => Present::iso($t->due_at), 'created' => Present::iso($t->created),
        ])->all());
    }

    public function update(Request $r, int $id)
    {
        $t = $this->visible($r)->where('tickets.id', $id)->first();
        $d = $this->body($r);
        if (!$t || !in_array($d['status'] ?? '', ['open', 'in_progress', 'resolved'], true)) {
            return $this->err('Not found.', 404);
        }
        $t->update(['status' => $d['status'], 'resolution' => mb_substr((string) ($d['resolution'] ?? ''), 0, 2000)]);
        Audit::record('ticket_update', (string) $id);
        Notifier::notify($t->user, 'ticket', 'Your request was updated.');
        return response()->json(['ok' => true]);
    }

    // ---------- finance ----------
    public function payouts()
    {
        return response()->json(Payout::with('clinician.user')->orderByDesc('id')->limit(200)->get()->map(fn ($p) => [
            'id' => $p->id, 'clinician' => $p->clinician->user->name, 'gross' => $p->gross, 'commission' => $p->commission, 'net' => $p->net, 'status' => $p->status,
        ])->all());
    }

    public function payoutPaid(int $id)
    {
        $p = Payout::find($id);
        if (!$p) {
            return $this->err('Not found.', 404);
        }
        $p->update(['status' => 'paid']);
        Audit::record('payout_paid', (string) $id);
        return response()->json(['ok' => true]);
    }

    public function refunds()
    {
        foreach (Payment::where('status', 'refund_pending')->get() as $p) {
            Billing::syncRefund($p);
        }
        return response()->json(Payment::where('status', 'refund_pending')->limit(200)->get()->map(fn ($p) => [
            'id' => $p->id, 'appointment_id' => $p->appointment_id, 'amount_etb' => $p->amount, 'ref' => $p->provider_ref, 'can_check' => (bool) $p->refund_id,
        ])->all());
    }

    public function retryRefund(int $id)
    {
        $p = Payment::find($id);
        if (!$p || $p->status !== 'refund_pending') {
            return $this->err('Not found.', 404);
        }
        $p->refund_id ? Billing::syncRefund($p) : Billing::refund($p, 'Retry by finance');
        Audit::record('refund_retry', $p->provider_ref);
        return response()->json(['status' => $p->fresh()->status]);
    }

    /** For when finance has confirmed in the Chapa dashboard that the money went back. */
    public function markRefunded(int $id)
    {
        $p = Payment::find($id);
        if (!$p || $p->status !== 'refund_pending') {
            return $this->err('Not found.', 404);
        }
        $p->update(['status' => 'refunded']);
        Audit::record('refund_marked_manually', $p->provider_ref);
        return response()->json(['status' => 'refunded']);
    }

    public function reports()
    {
        $by = Appointment::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        $sum = fn ($model, $col, $status) => (int) $model::where('status', $status)->sum($col);
        return response()->json([
            'appointments' => (object) $by, 'revenue_etb' => $sum(Payment::class, 'amount', 'paid'), 'refunds_etb' => $sum(Payment::class, 'amount', 'refunded'),
            'currency' => 'ETB', 'refunds_pending_etb' => $sum(Payment::class, 'amount', 'refund_pending'), 'commission_etb' => (int) Payout::sum('commission'),
            'payouts_pending_etb' => $sum(Payout::class, 'net', 'pending'), 'payouts_paid_etb' => $sum(Payout::class, 'net', 'paid'),
        ]);
    }
}

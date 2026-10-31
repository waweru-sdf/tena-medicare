<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Audit;
use App\Models\Clinician;
use App\Models\Notification;
use App\Models\User;
use App\Services\Ai;
use App\Services\Billing;
use App\Services\Cfg;
use App\Services\Chapa;
use App\Services\Notifier;
use App\Services\Present;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function clinicians()
    {
        $order = ['pending' => 0, 'draft' => 1, 'rejected' => 2, 'approved' => 3];
        $rows = Clinician::with('user')->get()->sortBy(fn ($c) => sprintf('%02d-%08d', $order[$c->status] ?? 9, $c->id))->values();
        return response()->json($rows->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->user->name, 'email' => $c->user->email, 'specialty' => $c->specialty, 'reg_number' => $c->reg_number, 'status' => $c->status,
            'bio' => (string) $c->bio, 'conditions' => (string) $c->conditions, 'years_exp' => $c->years_exp, 'language' => $c->language, 'photo_url' => Present::photoUrl($c),
            'price_chat' => Billing::price('chat'), 'price_video_chat' => Billing::price('video_chat'), 'submitted_at' => Present::iso($c->submitted_at),
            'review_reason' => $c->review_reason, 'missing' => Present::missing($c),
        ])->all());
    }

    public function review(Request $r, int $cid)
    {
        $d = $this->body($r);
        $c = Clinician::with('user')->find($cid);
        if (!$c || !in_array($d['decision'] ?? '', ['approved', 'rejected'], true)) {
            return $this->err('Invalid request.');
        }
        if ($c->status === 'draft') {
            return $this->err('This doctor has not submitted their profile yet.', 409);
        }
        $reason = trim((string) ($d['reason'] ?? ''));
        if ($d['decision'] === 'rejected' && $reason === '') {
            return $this->err('Give the doctor a reason so they can fix their profile.');
        }
        $c->update(['status' => $d['decision'], 'reviewed_by' => $this->who($r)->id, 'review_reason' => mb_substr($reason, 0, 255)]);
        Notifier::notify($c->user, 'doctor_review', $c->status === 'approved'
            ? 'Your Dr. Feysel Tena Medicine profile was approved. You can now add availability.'
            : 'Your profile needs changes before it can be approved.');
        Audit::record('clinician_' . $c->status, (string) $cid);
        return response()->json(['ok' => true]);
    }

    /** Metadata only: no clinical content. */
    public function appointments()
    {
        Billing::expireHolds();
        return response()->json(Appointment::with(['slot', 'clinician.user', 'patient'])->orderByDesc('id')->limit(200)->get()->map(fn ($a) => Present::appt($a))->all());
    }

    public function audit()
    {
        return response()->json(Audit::orderByDesc('id')->limit(200)->get()->map(fn ($x) => ['at' => Present::iso($x->at), 'user_id' => $x->user_id, 'action' => $x->action, 'detail' => $x->detail])->all());
    }

    public function config(Request $r)
    {
        if ($r->isMethod('put')) {
            $defaults = Cfg::defaults();
            foreach ($this->body($r) as $k => $v) {
                if (!array_key_exists($k, $defaults) || is_array($v)) {
                    continue;
                }
                if (in_array($k, Cfg::NUMERIC, true) && !is_numeric($v)) {
                    return $this->err(str_starts_with($k, 'price_') ? 'Prices must be whole numbers of birr, 1 or more.' : "{$k} must be a number.");
                }
                if (str_starts_with($k, 'price_') && ((float) $v < 1 || (float) $v != (int) $v)) {
                    return $this->err('Prices must be whole numbers of birr, 1 or more.');
                }
                Cfg::set($k, (string) $v);
            }
            Audit::record('config_change');
        }
        return response()->json(Cfg::all());
    }

    public function addStaff(Request $r)
    {
        $d = $this->body($r);
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if (!in_array($d['role'] ?? '', ['support', 'finance', 'compliance'], true) || mb_strlen((string) ($d['password'] ?? '')) < 8 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->err('Role, email and a password of 8+ characters are required.');
        }
        if (User::where('email', $email)->exists()) {
            return $this->err('That email is already registered.', 409);
        }
        User::create(['email' => $email, 'name' => mb_substr((string) ($d['name'] ?? ''), 0, 120), 'role' => $d['role'], 'password_hash' => Hash::make((string) $d['password'])]);
        Audit::record('staff_created', $d['role']);
        return response()->json(['ok' => true]);
    }

    public function notifications()
    {
        return response()->json(Notification::orderByDesc('id')->limit(50)->get()->map(fn ($n) => ['at' => Present::iso($n->at), 'kind' => $n->kind, 'status' => $n->status])->all());
    }

    /** One click in Settings: does the AI key work, does the Chapa key work, and if not, the provider's exact message. */
    public function integrationsTest(Request $r)
    {
        if ($x = $this->limit($r, 'integrations', 6)) {
            return $x;
        }
        $ai = ['provider' => Ai::provider(), 'model' => Ai::model()];
        if (!Ai::configured()) {
            $ai += ['ok' => false, 'message' => 'No API key found for ' . Ai::provider() . '. Add it to the .env file on the server.'];
        } else {
            try {
                Ai::call('Reply with the single word OK.', 'ping', 10);
                $ai += ['ok' => true, 'message' => 'Connected.'];
            } catch (\Throwable $e) {
                $ai += ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 300)];
            }
        }
        $ch = [];
        if (!Chapa::enabled()) {
            $ch = config('tena.production')
                ? ['ok' => false, 'mode' => 'none', 'message' => 'No CHAPA_SECRET_KEY set: payments are refused on the live site.']
                : ['ok' => true, 'mode' => 'simulated', 'message' => 'Test mode: payments are SIMULATED (no money moves). Add CHAPA_SECRET_KEY to .env to take real payments.'];
        } else {
            try {
                $code = Chapa::banksStatus();
                $ch = ['ok' => $code === 200, 'mode' => str_contains(strtoupper(Chapa::key()), 'TEST') ? 'test' : 'live',
                       'message' => $code === 200 ? 'Connected.' : "Chapa answered HTTP {$code}. Copy the secret key again from Settings > API."];
            } catch (\Throwable $e) {
                $ch = ['ok' => false, 'message' => 'Could not reach Chapa: ' . mb_substr($e->getMessage(), 0, 150)];
            }
            $ch['webhook_secret_set'] = (bool) config('tena.chapa_webhook_secret');
        }
        $vid = \App\Services\Jitsi::status();
        return response()->json(['ai' => $ai, 'chapa' => $ch, 'video' => $vid, 'webhook_url' => rtrim((string) config('app.url'), '/') . '/api/payments/chapa/webhook']);
    }
}

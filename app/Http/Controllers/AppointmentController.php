<?php

namespace App\Http\Controllers;

use App\Models\Amendment;
use App\Models\AiOutput;
use App\Models\Appointment;
use App\Models\AppointmentPhoto;
use App\Models\Audit;
use App\Models\Clinician;
use App\Models\Intake;
use App\Models\Note;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\Slot;
use App\Services\Billing;
use App\Services\Cfg;
use App\Services\Chapa;
use App\Services\Jitsi;
use App\Services\Notifier;
use App\Services\Present;
use App\Support\MediaStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppointmentController extends Controller
{
    // ---------- booking ----------
    public function book(Request $r)
    {
        $u = $this->who($r);
        if (!$u->consent_at) {
            return $this->err('Please accept the privacy notice first.', 403);
        }
        $d = $this->body($r);
        $tier = ($d['tier'] ?? '') ?: 'video_chat';
        if (!in_array($tier, Billing::BOOKABLE, true)) {
            return $this->err('Choose a plan: chat with a doctor, or video consultation + chat.');
        }
        Billing::expireHolds();   // free abandoned holds before the atomic slot claim
        DB::beginTransaction();
        try {
            if ($tier === 'chat') {
                // No appointment time: a placeholder slot (0 minutes, already taken) keeps one record shape for every plan.
                $c = Clinician::find((int) ($d['clinician_id'] ?? 0));
                if (!$c || $c->status !== 'approved') {
                    DB::rollBack();
                    return $this->err('That doctor is not available.', 409);
                }
                $at = now()->setMicroseconds(0);
                for ($i = 0; $i < 120 && Slot::where('clinician_id', $c->id)->where('starts_at', $at)->exists(); $i++) {
                    $at = $at->copy()->addSecond();   // starts_at is unique per doctor; move to the next free second
                }
                $s = Slot::create(['clinician_id' => $c->id, 'starts_at' => $at, 'duration_min' => 0, 'booked' => true]);
            } else {
                $slotId = (int) ($d['slot_id'] ?? 0);
                // atomic claim: only one concurrent request flips booked false -> true
                $claimed = Slot::where('id', $slotId)->where('booked', false)->where('starts_at', '>', now())->update(['booked' => true]);
                $s = $claimed ? Slot::find($slotId) : null;
                if (!$s || Clinician::find($s->clinician_id)?->status !== 'approved') {
                    DB::rollBack();
                    return $this->err('That slot is no longer available.', 409);
                }
            }
            $s->update(['hold_expires_at' => now()->addMinutes((int) Cfg::num('hold_minutes'))]);
            $a = Appointment::create([
                'id' => Billing::newId(), 'slot_id' => $s->id, 'patient_id' => $u->id, 'clinician_id' => $s->clinician_id,
                'reason' => mb_substr((string) ($d['reason'] ?? ''), 0, 500), 'status' => 'pending_payment', 'room' => 'tm-' . bin2hex(random_bytes(16)),
                'tier' => $tier, 'reminded' => false,
            ]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        Audit::record('book', (string) $s->clinician_id);
        return response()->json(Present::appt($a));
    }

    public function pay(Request $r, string $aid)
    {
        Billing::expireHolds();
        $a = $this->myAppt($r, $aid);
        if ($a && $a->status === 'expired') {
            return $this->err('This reservation expired. Please pick a time again.', 410);
        }
        if (!$a || $a->status !== 'pending_payment') {
            return $this->err('Appointment not found.', 404);
        }
        $u = $this->who($r);
        $fee = Billing::price($a->tier);
        $ref = Billing::newReference();
        $p = Payment::create(['appointment_id' => $a->id, 'amount' => $fee, 'method' => Chapa::enabled() ? 'chapa' : 'mock', 'provider_ref' => $ref, 'status' => 'initiated']);
        Audit::record('payment_initiated', $a->id);
        if (!Chapa::enabled()) {
            if (config('tena.production')) {
                $p->update(['status' => 'failed']);
                return $this->err('Payments are not configured. Please contact support.', 503);
            }
            return response()->json(['provider_ref' => $ref, 'currency' => 'ETB', 'message' => 'Development test mode: no Chapa key configured.']);
        }
        try {
            $parts = preg_split('/\s+/', trim($u->name ?: 'Patient'));
            $base = rtrim((string) config('app.url'), '/');
            $payload = [
                'amount' => (string) $fee, 'currency' => 'ETB', 'email' => $u->email, 'first_name' => $parts[0],
                'last_name' => implode(' ', array_slice($parts, 1)) ?: $parts[0], 'tx_ref' => $ref,
                'callback_url' => "{$base}/api/payments/chapa/callback?ref={$ref}", 'return_url' => "{$base}/?ref={$ref}",
                'customization' => ['title' => 'Dr. Feysel Tena Medicine', 'description' => Billing::TIERS[$a->tier] ?? 'Skin consultation'],
                'meta' => ['appointment_id' => $a->id],
            ];
            if ($ph = Chapa::phone($u->phone)) {
                $payload['phone_number'] = $ph;
            }
            $checkout = Chapa::initialize($payload);
        } catch (\Throwable $e) {
            Log::warning('Chapa initialize failed: ' . $e->getMessage());
            $p->update(['status' => 'failed']);
            return $this->err('We could not start the payment. Please try again.', 502);
        }
        return response()->json(['authorization_url' => $checkout, 'checkout_url' => $checkout, 'provider_ref' => $ref, 'currency' => 'ETB']);
    }

    /** Server-to-server check with Chapa. The browser coming back from Chapa is never payment proof. */
    public function verifyPayment(Request $r, string $ref)
    {
        $p = Payment::where('provider_ref', $ref)->first();
        $a = $p ? Appointment::find($p->appointment_id) : null;
        if (!$a || $a->patient_id !== $this->who($r)->id) {
            return $this->err('Not found.', 404);
        }
        if ($p->status === 'initiated' && Chapa::enabled()) {
            try {
                $v = Chapa::verify($ref);
                $d = $v['data'];
                if ($v['ok'] && ($d['status'] ?? '') === 'success') {
                    Billing::markPaid($p, $d['amount'] ?? null, $d['currency'] ?? '');
                } elseif (in_array($d['status'] ?? '', ['failed', 'abandoned', 'cancelled'], true)) {
                    $p->update(['status' => 'failed']);
                }
            } catch (\Throwable) {
            }
            $p->refresh();
        }
        return response()->json(['status' => $p->status, 'currency' => 'ETB']);
    }

    /** Test mode only: stands in for Chapa. Never available once a real Chapa key is set or in production. */
    public function simulate(Request $r, string $aid)
    {
        if (Chapa::enabled() || config('tena.production') || !config('tena.enable_dev_payments')) {
            return $this->err('disabled', 404);
        }
        $a = $this->myAppt($r, $aid);
        $p = $a ? Payment::where('appointment_id', $a->id)->where('status', 'initiated')->first() : null;
        if (!$p) {
            return $this->err('No pending payment.', 404);
        }
        Billing::expireHolds();
        $a->refresh();
        if ($a->status !== 'pending_payment') {
            return $this->err('This reservation expired. Please pick a time again.', 410);
        }
        $p->update(['status' => 'paid']);
        Billing::confirm($a);
        Audit::record('payment_paid', $p->provider_ref);
        return response()->json(Present::appt($a->fresh()));
    }

    public function index(Request $r)
    {
        Billing::expireHolds();
        $u = $this->who($r);
        $q = Appointment::select('appointments.*')->join('slots', 'appointments.slot_id', '=', 'slots.id')->with(['slot', 'clinician.user', 'patient']);
        $q = $u->role === 'patient' ? $q->where('appointments.patient_id', $u->id) : $q->where('appointments.clinician_id', $this->myClinician($r)->id);
        return response()->json($q->orderByDesc('slots.starts_at')->get()->map(fn ($a) => Present::appt($a))->all());
    }

    public function cancel(Request $r, string $aid)
    {
        $a = $this->myAppt($r, $aid);
        $u = $this->who($r);
        if (!$a || !in_array($a->status, ['pending_payment', 'confirmed'], true)) {
            return $this->err('Cannot cancel this appointment.');
        }
        if ($a->tier === 'chat' && $a->status === 'confirmed' && $u->role === 'patient') {
            return $this->err('A paid chat plan cannot be cancelled here. Contact support if something went wrong.');
        }
        $paid = Payment::where('appointment_id', $a->id)->where('status', 'paid')->first();
        $hoursLeft = ($a->slot->starts_at->getTimestamp() - time()) / 3600;
        $refundDue = $paid && ($u->role === 'clinician' || $hoursLeft >= Cfg::num('cancel_hours'));
        if ($refundDue) {
            Billing::refund($paid, 'Cancelled by ' . $u->role);
        }
        $a->update(['status' => 'cancelled']);
        $a->slot->update(['booked' => false, 'hold_expires_at' => null]);
        Audit::record('cancel', $aid);
        Notifier::notify($a->patient, 'cancel', 'An appointment was cancelled.');
        return response()->json(['ok' => true, 'refund' => $refundDue ? $paid->fresh()->status : null]);
    }

    public function reschedule(Request $r, string $aid)
    {
        $a = $this->myAppt($r, $aid);
        $d = $this->body($r);
        if (!$a || !in_array($a->status, ['pending_payment', 'confirmed'], true) || $a->tier === 'chat') {
            return $this->err('This appointment cannot be rescheduled.');
        }
        if (($a->slot->starts_at->getTimestamp() - time()) / 3600 < Cfg::num('cancel_hours')) {
            return $this->err('Changes are not allowed within ' . Cfg::get('cancel_hours') . ' hours of the appointment.');
        }
        $new = Slot::find((int) ($d['slot_id'] ?? 0));
        if (!$new || $new->clinician_id !== $a->clinician_id || $new->starts_at->lte(now())) {
            return $this->err("Choose one of this doctor's open times.");
        }
        DB::beginTransaction();
        if (!Slot::where('id', $new->id)->where('booked', false)->update(['booked' => true])) {
            DB::rollBack();
            return $this->err('That time was just taken. Pick another.', 409);
        }
        $old = $a->slot;
        $new->update(['hold_expires_at' => $old->hold_expires_at]);   // an unpaid hold keeps its original deadline
        $old->update(['booked' => false, 'hold_expires_at' => null]);
        $a->update(['slot_id' => $new->id, 'reminded' => false]);
        DB::commit();
        Audit::record('reschedule', $aid);
        Notifier::notify($a->patient, 'reschedule', 'Your appointment time was changed.');
        return response()->json(['ok' => true]);
    }

    public function noShow(Request $r, string $aid)
    {
        $a = $this->myAppt($r, $aid);
        if (!$a || $a->status !== 'confirmed' || now()->lt($a->slot->starts_at->copy()->addMinutes(10))) {
            return $this->err('You can mark a no-show 10 minutes after the start time.');
        }
        $a->update(['status' => 'no_show']);
        Audit::record('no_show', $aid);
        return response()->json(['ok' => true]);
    }

    // ---------- the video visit ----------
    public function room(Request $r, string $aid)
    {
        $a = $this->myAppt($r, $aid);
        if (!$a || !in_array($a->status, ['confirmed', 'checked_in', 'in_consultation'], true)) {
            return $this->err('Room not available.', 403);
        }
        if ($a->tier === 'chat') {
            return $this->err('This plan is chat only. Use Messages to talk to your doctor.');
        }
        $start = $a->slot->starts_at;
        if (now()->lt($start->copy()->subMinutes(10)) || now()->gt($start->copy()->addMinutes($a->slot->duration_min + 30))) {
            return $this->err('The room opens 10 minutes before your appointment.', 403);
        }
        $url = Jitsi::url($a, $this->who($r));
        if (!$url) {
            return $this->err('Private video rooms are not configured. Contact the service administrator.', 503);
        }
        $a->update(['status' => 'in_consultation']);
        Audit::record('join_room', $aid);
        return response()->json(['url' => $url]);
    }

    // ---------- the doctor's notes and prescriptions ----------
    public function note(Request $r, string $aid)
    {
        $a = $this->myAppt($r, $aid);
        if (!$a) {
            return $this->err('Not found.', 404);
        }
        if ($lock = $this->clinicalLocked($a)) {
            return $lock;
        }
        $n = Note::where('appointment_id', $aid)->first() ?: Note::create(['appointment_id' => $aid, 'assessment' => '', 'plan' => '', 'summary_for_patient' => '', 'finalized' => false]);
        if ($r->isMethod('put')) {
            if ($n->finalized) {
                return $this->err('Signed notes cannot be edited. Add an amendment.', 409);
            }
            $d = $this->body($r);
            foreach (['assessment', 'plan', 'summary_for_patient'] as $f) {
                $n->{$f} = array_key_exists($f, $d) ? (string) $d[$f] : (string) $n->{$f};
            }
            $n->save();
            Audit::record('note_save', $aid);
        } else {
            Audit::record('note_view', $aid);
        }
        $am = Amendment::where('note_id', $n->id)->get();
        return response()->json([
            'assessment' => $n->assessment, 'plan' => $n->plan, 'summary_for_patient' => $n->summary_for_patient, 'finalized' => (bool) $n->finalized,
            'amendments' => $am->map(fn ($x) => ['reason' => $x->reason, 'text' => $x->text, 'at' => $x->created_at?->toIso8601String()])->all(),
        ]);
    }

    public function finalize(Request $r, string $aid)
    {
        $a = $this->myAppt($r, $aid);
        if ($a && ($lock = $this->clinicalLocked($a))) {
            return $lock;
        }
        $n = $a ? Note::where('appointment_id', $aid)->first() : null;
        if (!$n || $n->finalized) {
            return $this->err('Nothing to sign.');
        }
        $n->update(['finalized' => true, 'finalized_at' => now()]);
        $a->update(['status' => 'completed']);
        Audit::record('note_signed', $aid);
        Billing::makePayout($a);
        AiOutput::where('appointment_id', $aid)->where('status', 'draft')->whereIn('feature', ['draft_note', 'post_call'])
            ->update(['status' => 'approved', 'approved_by' => $this->who($r)->id, 'approved_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function amend(Request $r, string $aid)
    {
        $a = $this->myAppt($r, $aid);
        if ($a && ($lock = $this->clinicalLocked($a))) {
            return $lock;
        }
        $n = $a ? Note::where('appointment_id', $aid)->where('finalized', true)->first() : null;
        $d = $this->body($r);
        if (!$n || empty($d['reason']) || empty($d['text'])) {
            return $this->err('A reason and amendment text are required.');
        }
        Amendment::create(['note_id' => $n->id, 'author_id' => $this->who($r)->id, 'reason' => mb_substr((string) $d['reason'], 0, 255), 'text' => (string) $d['text'], 'created_at' => now()]);
        Audit::record('note_amended', $aid);
        return response()->json(['ok' => true]);
    }

    public function prescribe(Request $r, string $aid)
    {
        $a = $this->myAppt($r, $aid);
        $d = $this->body($r);
        if (!$a) {
            return $this->err('Not found.', 404);
        }
        if ($lock = $this->clinicalLocked($a)) {
            return $lock;
        }
        if (!in_array($a->status, Billing::LIVE_OR_DONE, true)) {
            return $this->err('The visit must be paid and confirmed before prescribing.', 409);
        }
        $need = ['medicine' => 120, 'dose' => 60, 'route' => 40, 'frequency' => 60, 'duration' => 60, 'quantity' => 40];
        $row = [];
        foreach ($need as $k => $max) {
            $v = trim((string) ($d[$k] ?? ''));
            if ($v === '') {
                return $this->err('Complete all prescription fields.');
            }
            $row[$k] = mb_substr($v, 0, $max);
        }
        Prescription::create($row + ['appointment_id' => $aid, 'prescriber_id' => $this->who($r)->id, 'instructions' => mb_substr((string) ($d['instructions'] ?? ''), 0, 255), 'issued_at' => now()]);
        Audit::record('prescription_issued', $aid);
        Notifier::notify($a->patient, 'prescription', 'A new prescription is available.');
        return response()->json(['ok' => true]);
    }

    // ---------- one visit, its photos and the intake form ----------
    /** One visit. A doctor only sees the patient's message, photos and saved details once the visit is paid. */
    public function show(Request $r, string $aid)
    {
        $a = $this->myAppt($r, $aid);
        if (!$a) {
            return $this->err('Not found.', 404);
        }
        $u = $this->who($r);
        $d = Present::appt($a);
        $locked = $u->role === 'clinician' && !(in_array($a->status, Billing::LIVE_OR_DONE, true) && Billing::isPaid($a));
        $d['locked'] = $locked;
        if ($locked) {
            $d['reason'] = null;
            $d['photo_ids'] = [];
        } else {
            $d['photo_ids'] = AppointmentPhoto::where('appointment_id', $a->id)->pluck('media_id')->all();
            if ($u->role === 'clinician') {
                $d['patient_profile'] = Present::loadProfile($a->patient_id);
            }
            $d['prescriptions'] = Prescription::where('appointment_id', $a->id)->get()->map(fn ($x) => Present::rx($x))->all();
        }
        return response()->json($d);
    }

    public function addPhoto(Request $r, string $aid)
    {
        if ($x = $this->limit($r, 'photo', 20)) {
            return $x;
        }
        $a = $this->myAppt($r, $aid);
        if (!$a || !in_array($a->status, ['pending_payment', 'confirmed', 'checked_in'], true)) {
            return $this->err('Photos can be added to a booked visit before it starts.');
        }
        if (AppointmentPhoto::where('appointment_id', $aid)->count() >= 4) {
            return $this->err('You can attach up to 4 photos to a visit. Tier 2 patients can send more in chat.');
        }
        [$m, $err] = MediaStore::saveUpload($r->file('photo'), 'visit', true, $this->who($r)->id);
        if ($err) {
            return $this->err($err);
        }
        AppointmentPhoto::create(['appointment_id' => $aid, 'media_id' => $m->id, 'caption' => mb_substr((string) $r->input('caption', ''), 0, 120)]);
        Audit::record('visit_photo_added', $aid);
        return response()->json(['photo_ids' => AppointmentPhoto::where('appointment_id', $aid)->pluck('media_id')->all()]);
    }

    public function deletePhoto(Request $r, string $aid, string $mid)
    {
        $a = $this->myAppt($r, $aid);
        $row = $a ? AppointmentPhoto::where('appointment_id', $aid)->where('media_id', $mid)->first() : null;
        if (!$row) {
            return $this->err('Not found.', 404);
        }
        $row->delete();
        MediaStore::discard($mid);
        return response()->json(['ok' => true]);
    }

    public function intake(Request $r, string $aid)
    {
        $a = $this->myAppt($r, $aid);
        if (!$a) {
            return $this->err('Not found.', 404);
        }
        $u = $this->who($r);
        if ($r->isMethod('put')) {
            if ($u->role !== 'patient' || !in_array($a->status, ['pending_payment', 'confirmed'], true) || $a->slot->starts_at->lte(now())) {
                return $this->err('Intake can only be submitted by the booked patient before the visit starts.', 403);
            }
            $d = $this->body($r);
            $data = [];
            foreach (['reason_for_visit', 'symptoms_or_concerns', 'current_medications', 'allergies', 'relevant_history', 'preferred_language'] as $k) {
                $data[$k] = mb_substr((string) ($d[$k] ?? ''), 0, 1500);
            }
            $rec = Intake::firstOrNew(['appointment_id' => $aid]);
            $rec->patient_id = $u->id;
            $rec->answers = json_encode($data, JSON_UNESCAPED_UNICODE);
            $rec->updated_at = now();
            $rec->save();
            Audit::record('previsit_intake_saved', $aid);
            return response()->json(['ok' => true]);
        }
        $rec = Intake::where('appointment_id', $aid)->first();
        return response()->json(['answers' => $rec ? (object) (json_decode((string) $rec->answers, true) ?: []) : (object) []]);
    }

    // ---------- the patient's records ----------
    public function records(Request $r)
    {
        $u = $this->who($r);
        $out = [];
        foreach (Appointment::with(['slot', 'clinician.user', 'patient'])->where('patient_id', $u->id)->where('status', 'completed')->get() as $a) {
            $n = Note::where('appointment_id', $a->id)->where('finalized', true)->first();
            $rx = Prescription::where('appointment_id', $a->id)->get();
            $out[] = [
                'appointment' => Present::appt($a), 'summary' => $n ? $n->summary_for_patient : '', 'plan' => $n ? $n->plan : '',
                'prescriptions' => $rx->map(fn ($p) => ['medicine' => $p->medicine, 'dose' => $p->dose, 'route' => $p->route, 'frequency' => $p->frequency,
                    'duration' => $p->duration, 'quantity' => $p->quantity, 'instructions' => $p->instructions, 'issued_at' => $p->issued_at?->toIso8601String()])->all(),
            ];
        }
        Audit::record('records_view');
        return response()->json($out);
    }

    public function prescriptions(Request $r)
    {
        $u = $this->who($r);
        $out = [];
        foreach (Appointment::with(['slot', 'clinician.user'])->where('patient_id', $u->id)->whereIn('status', Billing::LIVE_OR_DONE)->get() as $a) {
            foreach (Prescription::where('appointment_id', $a->id)->get() as $x) {
                $out[] = Present::rx($x) + ['doctor' => $a->clinician->user->name, 'specialty' => $a->clinician->specialty, 'visit_at' => Present::iso($a->slot->starts_at)];
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['issued_at'], (string) $a['issued_at']));
        return response()->json($out);
    }
}

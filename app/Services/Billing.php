<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Audit;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Slot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Booking, payment and refund rules. Everything that moves money or a slot lives here, in one place. */
class Billing
{
    public const LIVE_OR_DONE = ['confirmed', 'checked_in', 'in_consultation', 'completed'];
    public const TIERS = ['chat' => 'Chat with a doctor', 'video_chat' => 'Video consultation + chat', 'video' => 'Video visit (earlier plan)'];
    public const BOOKABLE = ['chat', 'video_chat'];
    public const CHAT_TIERS = ['chat', 'video_chat'];
    public const CURRENCY = 'ETB';

    /** One price per plan for every doctor. Only the admin changes it (Settings > Prices). */
    public static function price(?string $tier): int
    {
        return (int) Cfg::num($tier === 'chat' ? 'price_chat' : 'price_video_chat');
    }

    public static function isPaid(Appointment $a): bool
    {
        return Payment::where('appointment_id', $a->id)->where('status', 'paid')->exists();
    }

    public static function paymentState(Appointment $a): ?string
    {
        return Payment::where('appointment_id', $a->id)->orderByDesc('id')->value('status');
    }

    /** End of the open chat window for this patient and doctor, or null. Only a paid chat plan opens it. */
    public static function chatWindow(int $patientId, int $clinicianId): ?Carbon
    {
        $best = null;
        $rows = Appointment::where('patient_id', $patientId)->where('clinician_id', $clinicianId)
            ->whereIn('tier', self::CHAT_TIERS)->whereIn('status', self::LIVE_OR_DONE)->whereNotNull('chat_until')->where('chat_until', '>', now())->get();
        foreach ($rows as $a) {
            if (self::isPaid($a) && (!$best || $a->chat_until->gt($best))) {
                $best = $a->chat_until;
            }
        }
        return $best;
    }

    /** A verified payment turns a held slot into a confirmed visit, and opens chat for the chat plans. */
    public static function confirm(Appointment $a): void
    {
        $a->status = 'confirmed';
        $a->slot->hold_expires_at = null;
        $a->slot->save();
        if (in_array($a->tier, self::CHAT_TIERS, true)) {
            $start = $a->tier === 'chat' ? now() : $a->slot->starts_at->copy()->addMinutes($a->slot->duration_min);   // chat-only starts when paid
            $a->chat_until = $start->addDays((int) Cfg::num('chat_days'));
            Conversation::firstOrCreate(['patient_id' => $a->patient_id, 'clinician_id' => $a->clinician_id]);
        }
        $a->save();
    }

    /** Frees slots whose payment window ran out. Runs on every booking request, so it needs no background process. */
    public static function expireHolds(): int
    {
        $n = 0;
        $rows = Appointment::with('slot')->where('status', 'pending_payment')
            ->whereHas('slot', fn ($q) => $q->whereNotNull('hold_expires_at')->where('hold_expires_at', '<=', now()))->get();
        foreach ($rows as $a) {
            $a->status = 'expired';
            $a->save();
            $a->slot->update(['booked' => false, 'hold_expires_at' => null]);
            Audit::record('hold_expired', $a->id);
            $n++;
        }
        return $n;
    }

    /** Confirms only a verified Chapa payment whose amount and currency match what we asked for. */
    public static function markPaid(Payment $p, $amount, $currency): void
    {
        if ($p->status === 'paid') {
            return;
        }
        $matches = is_numeric($amount) && round((float) $amount, 2) === round((float) $p->amount, 2);
        if (!$matches || strtoupper((string) $currency) !== self::CURRENCY) {
            $p->update(['status' => 'failed']);
            Audit::record('payment_mismatch', $p->provider_ref);
            return;
        }
        $p->update(['status' => 'paid']);
        $ap = Appointment::with(['slot', 'patient', 'clinician.user'])->find($p->appointment_id);
        if ($ap) {
            if ($ap->status === 'pending_payment' && $ap->slot->hold_expires_at && $ap->slot->hold_expires_at->lte(now())) {
                $ap->update(['status' => 'expired']);
                $ap->slot->update(['booked' => false, 'hold_expires_at' => null]);
            }
            if ($ap->status === 'expired') {   // paid just after the hold ran out: keep the booking only if nobody took the slot
                if (Slot::where('id', $ap->slot_id)->where('booked', false)->update(['booked' => true])) {
                    $ap->update(['status' => 'pending_payment']);
                    $ap->slot->refresh();
                } else {
                    Audit::record('late_payment_refund', $p->provider_ref);
                    self::refund($p, 'Time slot no longer available');
                    Notifier::notify($ap->patient, 'refund', 'Your payment is being refunded because the time was taken.');
                    return;
                }
            } elseif ($ap->status !== 'pending_payment') {   // e.g. cancelled while the payment was in flight
                Audit::record('late_payment_refund', $p->provider_ref);
                self::refund($p, 'Appointment no longer active');
                return;
            }
            self::confirm($ap);
            Notifier::notify($ap->patient, 'booking', 'Your appointment is confirmed.');
            Notifier::notify($ap->clinician->user, 'booking', 'You have a new confirmed appointment.');
        }
        Audit::record('payment_paid', $p->provider_ref);
    }

    /**
     * Asks Chapa for a refund. If anything fails the payment stays 'refund_pending' so finance can retry;
     * the patient is never left without a cancelled booking just because Chapa was unreachable.
     */
    public static function refund(Payment $p, string $why = 'Appointment cancelled'): bool
    {
        if (!in_array($p->status, ['paid', 'refund_pending', 'review'], true)) {
            return false;
        }
        if ($p->method !== 'chapa' || !Chapa::enabled()) {   // local test payments have no provider money to return
            $p->update(['status' => 'refunded']);
            Audit::record('refund_test_mode', $p->provider_ref);
            return true;
        }
        $p->refund_ref = $p->refund_ref ?: 'RF' . strtoupper(substr(bin2hex(random_bytes(10)), 0, 18));
        try {
            $r = Chapa::refund($p->provider_ref, $p->refund_ref, $why);
            $p->refund_id = $r['ref_id'] ?: $p->refund_id;
            $p->status = 'refund_pending';
            $p->save();
            Audit::record('refund_requested', $p->provider_ref);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Chapa refund failed: ' . $e->getMessage());
            $p->status = 'refund_pending';
            $p->save();
            Audit::record('refund_request_failed', $p->provider_ref);
            return false;
        }
    }

    /** Chapa says when a refund really finished: initiated -> processing -> refunded | reversed. */
    public static function syncRefund(Payment $p): string
    {
        if ($p->status !== 'refund_pending' || !$p->refund_id || !Chapa::enabled()) {
            return $p->status;
        }
        try {
            $st = Chapa::refundStatus($p->refund_id);
            if ($st === 'refunded') {
                $p->update(['status' => 'refunded']);
                Audit::record('refund_completed', $p->provider_ref);
            } elseif ($st === 'reversed') {
                $p->update(['refund_id' => null]);   // money is back with us; finance retries
                Audit::record('refund_reversed', $p->provider_ref);
            }
        } catch (\Throwable) {
        }
        return $p->status;
    }

    /** When a visit is signed, the doctor's share is recorded for finance to pay out. */
    public static function makePayout(Appointment $a): void
    {
        if (Payout::where('appointment_id', $a->id)->exists()) {
            return;
        }
        $paid = Payment::where('appointment_id', $a->id)->where('status', 'paid')->first();
        if ($paid) {
            $c = (int) round($paid->amount * Cfg::num('commission_pct') / 100);
            Payout::create(['appointment_id' => $a->id, 'clinician_id' => $a->clinician_id, 'gross' => $paid->amount, 'commission' => $c, 'net' => $paid->amount - $c, 'status' => 'pending']);
        }
        Notifier::notify($a->patient, 'documents', 'Your visit documents are ready.');
    }

    public static function newReference(): string
    {
        return 'TM' . strtoupper(substr(bin2hex(random_bytes(10)), 0, 20));
    }

    public static function newId(): string
    {
        return (string) Str::uuid();
    }
}

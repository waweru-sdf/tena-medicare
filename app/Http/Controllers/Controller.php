<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Clinician;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing;
use App\Support\Throttle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function err(string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json(['error' => $message] + $extra, $status);
    }

    /** Per-visitor limit for one action, e.g. 10 sign-in attempts a minute. Returns an error reply when the limit is hit. */
    protected function limit(Request $r, string $name, int $max, int $seconds = 60): ?JsonResponse
    {
        if (!Throttle::allow("tena:{$name}:" . $r->ip(), $max, $seconds)) {
            return $this->err('Too many attempts. Wait a minute and try again.', 429);
        }
        return null;
    }

    protected function who(Request $r): User
    {
        return $r->attributes->get('user');
    }

    protected function myClinician(Request $r): ?Clinician
    {
        return Clinician::with('user')->where('user_id', $this->who($r)->id)->first();
    }

    /** Only the booked patient or the assigned doctor may touch an appointment. */
    protected function myAppt(Request $r, string $id): ?Appointment
    {
        $a = Appointment::with(['slot', 'clinician.user', 'patient'])->find($id);
        $u = $this->who($r);
        if (!$a) {
            return null;
        }
        if ($u->role === 'patient' && $a->patient_id === $u->id) {
            return $a;
        }
        if ($u->role === 'clinician' && $a->clinician->user_id === $u->id) {
            return $a;
        }
        return null;
    }

    /** Notes, prescriptions and doctor AI work only on a paid appointment that is live or finished. */
    protected function clinicalLocked(Appointment $a): ?JsonResponse
    {
        if (in_array($a->status, Billing::LIVE_OR_DONE, true) && Billing::isPaid($a)) {
            return null;
        }
        return $this->err('This appointment is not paid and confirmed, so notes and prescriptions are locked.', 402);
    }

    protected function body(Request $r): array
    {
        $d = $r->json()->all() ?: $r->all();
        return is_array($d) ? $d : [];
    }
}

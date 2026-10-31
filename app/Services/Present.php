<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentPhoto;
use App\Models\Clinician;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PatientProfile;
use App\Models\Prescription;
use App\Models\User;

/** Turns database rows into the JSON the website expects (same shapes as the original Tena API). */
class Present
{
    public const PROFILE_FIELDS = ['date_of_birth', 'sex', 'skin_type', 'allergies', 'medications', 'history', 'language'];

    public static function iso($d): ?string
    {
        return $d ? $d->copy()->utc()->format('Y-m-d\TH:i:s') . 'Z' : null;
    }

    public static function photoUrl(?Clinician $c): ?string
    {
        return $c && $c->photo_id ? "/api/media/{$c->photo_id}" : null;
    }

    public static function user(User $u): array
    {
        $d = ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'consented' => (bool) $u->consent_at, 'mfa' => (bool) $u->mfa_on];
        if ($u->role === 'clinician' && ($c = Clinician::where('user_id', $u->id)->first())) {
            $d += ['clinician_id' => $c->id, 'doctor_status' => $c->status, 'photo_url' => self::photoUrl($c)];
        }
        return $d;
    }

    public static function convOf(Appointment $a): ?int
    {
        if (!in_array($a->tier ?: 'video', Billing::CHAT_TIERS, true)) {
            return null;
        }
        return Conversation::where('patient_id', $a->patient_id)->where('clinician_id', $a->clinician_id)->value('id');
    }

    public static function appt(Appointment $a): array
    {
        $a->loadMissing(['slot', 'clinician.user', 'patient']);
        $tier = $a->tier ?: 'video';
        return [
            'id' => $a->id, 'status' => $a->status, 'reason' => $a->reason, 'starts_at' => self::iso($a->slot->starts_at),
            'clinician' => $a->clinician->user->name, 'specialty' => $a->clinician->specialty, 'fee_etb' => Billing::price($a->tier),
            'patient' => $a->patient->name, 'patient_id' => $a->patient_id, 'clinician_id' => $a->clinician_id,
            'payment' => Billing::paymentState($a), 'tier' => $tier, 'tier_label' => Billing::TIERS[$tier] ?? null,
            'photo_url' => self::photoUrl($a->clinician), 'chat_until' => self::iso($a->chat_until),
            'chat_open' => (bool) Billing::chatWindow($a->patient_id, $a->clinician_id) && in_array($a->tier, Billing::CHAT_TIERS, true),
            'photos' => AppointmentPhoto::where('appointment_id', $a->id)->count(),
            'conversation_id' => self::convOf($a),
            'hold_expires_at' => $a->status === 'pending_payment' && $a->slot->hold_expires_at ? self::iso($a->slot->hold_expires_at) : null,
        ];
    }

    public static function conditions(Clinician $c): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $c->conditions)), fn ($x) => $x !== ''));
    }

    public static function card(Clinician $c, bool $full = false, ?int $slots = null): array
    {
        $bio = (string) $c->bio;
        return [
            'id' => $c->id, 'name' => $c->user->name, 'specialty' => $c->specialty, 'language' => $c->language, 'conditions' => self::conditions($c),
            'years_exp' => $c->years_exp, 'photo_url' => self::photoUrl($c), 'price_chat' => Billing::price('chat'), 'price_video_chat' => Billing::price('video_chat'),
            'bio' => $full ? $bio : mb_substr($bio, 0, 160), 'open_slots' => $slots,
        ];
    }

    /** What a doctor still has to fill in before asking the admin for approval. */
    public static function missing(Clinician $c): array
    {
        $miss = [];
        if (mb_strlen(trim((string) $c->bio)) < 40) {
            $miss[] = 'A short description of what you do (at least 40 characters)';
        }
        if (!$c->photo_id) {
            $miss[] = 'A profile photo';
        }
        if (trim((string) $c->conditions) === '') {
            $miss[] = 'The conditions you treat';
        }
        if (trim((string) $c->reg_number) === '') {
            $miss[] = 'Your medical registration number';
        }
        return $miss;
    }

    public static function profile(Clinician $c): array
    {
        return [
            'specialty' => $c->specialty, 'language' => $c->language, 'price_chat' => Billing::price('chat'), 'price_video_chat' => Billing::price('video_chat'),
            'currency' => 'ETB', 'status' => $c->status, 'review_reason' => $c->review_reason, 'reg_number' => $c->reg_number, 'bio' => (string) $c->bio,
            'conditions' => (string) $c->conditions, 'years_exp' => $c->years_exp, 'photo_url' => self::photoUrl($c), 'missing' => self::missing($c),
            'name' => $c->user->name, 'submitted_at' => self::iso($c->submitted_at),
        ];
    }

    public static function rx(Prescription $p): array
    {
        return [
            'id' => $p->id, 'appointment_id' => $p->appointment_id, 'medicine' => $p->medicine, 'dose' => $p->dose, 'route' => $p->route,
            'frequency' => $p->frequency, 'duration' => $p->duration, 'quantity' => $p->quantity, 'instructions' => $p->instructions, 'issued_at' => self::iso($p->issued_at),
        ];
    }

    public static function loadProfile(int $userId): array
    {
        $row = PatientProfile::find($userId);
        $data = $row && $row->data ? (json_decode($row->data, true) ?: []) : [];
        $out = [];
        foreach (self::PROFILE_FIELDS as $k) {
            $out[$k] = $data[$k] ?? '';
        }
        return $out;
    }

    public static function msg(Message $m, User $me): array
    {
        return ['id' => $m->id, 'mine' => $m->sender_id === $me->id, 'body' => $m->body ?? '', 'image_url' => $m->media_id ? "/api/media/{$m->media_id}" : null,
                'at' => self::iso($m->created_at), 'read' => (bool) $m->read_at];
    }

    public static function conv(Conversation $cv, User $me): array
    {
        $cv->loadMissing(['clinician.user', 'patient']);
        $otherIsDoc = $me->role === 'patient';
        $other = $otherIsDoc ? $cv->clinician->user : $cv->patient;
        $last = Message::where('conversation_id', $cv->id)->orderByDesc('id')->first();
        $unread = Message::where('conversation_id', $cv->id)->where('sender_id', '!=', $me->id)->whereNull('read_at')->count();
        $until = Billing::chatWindow($cv->patient_id, $cv->clinician_id);
        return [
            'id' => $cv->id, 'other_name' => $other->name, 'other_role' => $otherIsDoc ? 'clinician' : 'patient',
            'other_id' => $otherIsDoc ? $cv->clinician_id : $cv->patient_id, 'other_photo' => $otherIsDoc ? self::photoUrl($cv->clinician) : null,
            'specialty' => $otherIsDoc ? $cv->clinician->specialty : null,
            'last' => $last ? (($last->sender_id === $me->id ? 'You: ' : '') . ($last->body ?: 'Photo')) : '',
            'last_at' => $last ? self::iso($last->created_at) : null, 'unread' => $unread, 'chat_open' => (bool) $until, 'chat_until' => self::iso($until),
        ];
    }

    /** One doctor-patient pair: their paid visits, chat status, unread count and prescriptions. */
    public static function team(User $me, int $patientId, int $clinicianId, $appts): array
    {
        $cv = Conversation::where('patient_id', $patientId)->where('clinician_id', $clinicianId)->first();
        $until = Billing::chatWindow($patientId, $clinicianId);
        $appts = $appts->sortBy(fn ($a) => $a->slot->starts_at)->values();
        $last = $appts->last();
        $unread = $cv ? Message::where('conversation_id', $cv->id)->where('sender_id', '!=', $me->id)->whereNull('read_at')->count() : 0;
        return [
            'visits' => $appts->map(fn ($a) => ['id' => $a->id, 'starts_at' => self::iso($a->slot->starts_at), 'status' => $a->status, 'tier' => $a->tier ?: 'video', 'reason' => $a->reason])->all(),
            'last_visit' => self::iso($last->slot->starts_at), 'chat_open' => (bool) $until, 'chat_until' => self::iso($until),
            'conversation_id' => $cv?->id, 'unread' => $unread,
            'prescriptions' => Prescription::whereIn('appointment_id', $appts->pluck('id'))->count(),
        ];
    }
}

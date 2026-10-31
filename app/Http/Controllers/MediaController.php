<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TenaAuth;
use App\Models\Appointment;
use App\Models\AppointmentPhoto;
use App\Models\Conversation;
use App\Models\Media;
use App\Models\Message;
use App\Services\Billing;
use App\Support\MediaStore;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    /** Doctor profile photos are public. Every other photo is only for the people it was shared with. */
    public function show(Request $r, string $id)
    {
        $m = Media::find($id);
        if (!$m) {
            return $this->err('Not found.', 404);
        }
        if ($m->kind !== 'profile') {
            $u = TenaAuth::resolve($r);
            $ok = false;
            if ($u) {
                $ok = $m->owner_id === $u->id;
                $row = AppointmentPhoto::where('media_id', $id)->first();
                if ($row && !$ok) {
                    $a = Appointment::with('clinician')->find($row->appointment_id);
                    $ok = (bool) ($a && (($u->role === 'patient' && $a->patient_id === $u->id)
                        || ($u->role === 'clinician' && $a->clinician->user_id === $u->id && in_array($a->status, Billing::LIVE_OR_DONE, true) && Billing::isPaid($a))));
                }
                $msg = Message::where('media_id', $id)->first();
                if ($msg && !$ok) {
                    $cv = Conversation::with('clinician')->find($msg->conversation_id);
                    $ok = in_array($u->id, [$cv->patient_id, $cv->clinician->user_id], true);
                }
            }
            if (!$ok) {
                return $this->err('You do not have access to this photo.', 403);
            }
        }
        $raw = MediaStore::read($m);
        if ($raw === null) {
            return $this->err('Not found.', 404);
        }
        return response($raw, 200, ['Content-Type' => $m->mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $m->kind === 'profile' ? 'public, max-age=3600' : 'private, max-age=86400']);
    }
}

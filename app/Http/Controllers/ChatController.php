<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Audit;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Billing;
use App\Services\Cfg;
use App\Services\Notifier;
use App\Services\Present;
use App\Support\MediaStore;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    private function myConv(Request $r, int $id): ?Conversation
    {
        $cv = Conversation::with('clinician.user')->find($id);
        $u = $this->who($r);
        if (!$cv) {
            return null;
        }
        if ($u->role === 'patient' && $cv->patient_id === $u->id) {
            return $cv;
        }
        if ($u->role === 'clinician' && $cv->clinician->user_id === $u->id) {
            return $cv;
        }
        return null;
    }

    /** Makes sure every paid chat visit of mine has its thread (covers visits paid before the feature existed). */
    private function ensureConversations(Request $r): void
    {
        $u = $this->who($r);
        $q = Appointment::with('slot')->whereIn('tier', Billing::CHAT_TIERS);
        $q = $u->role === 'patient' ? $q->where('patient_id', $u->id) : $q->where('clinician_id', $this->myClinician($r)->id);
        foreach ($q->get() as $a) {
            if (in_array($a->status, Billing::LIVE_OR_DONE, true) && Billing::isPaid($a)) {
                if (!$a->chat_until) {
                    $a->update(['chat_until' => $a->slot->starts_at->copy()->addMinutes($a->slot->duration_min)->addDays((int) Cfg::num('chat_days'))]);
                }
                Conversation::firstOrCreate(['patient_id' => $a->patient_id, 'clinician_id' => $a->clinician_id]);
            }
        }
    }

    public function conversations(Request $r)
    {
        $this->ensureConversations($r);
        $u = $this->who($r);
        $q = $u->role === 'patient' ? Conversation::where('patient_id', $u->id) : Conversation::where('clinician_id', $this->myClinician($r)->id);
        $out = $q->get()->map(fn ($c) => Present::conv($c, $u))->all();
        usort($out, fn ($a, $b) => strcmp((string) $b['last_at'], (string) $a['last_at']));
        return response()->json($out);
    }

    public function messages(Request $r, int $cid)
    {
        $cv = $this->myConv($r, $cid);
        if (!$cv) {
            return $this->err('Not found.', 404);
        }
        $u = $this->who($r);
        $after = (int) $r->query('after', 0);
        $rows = Message::where('conversation_id', $cid)->where('id', '>', $after)->orderBy('id')->limit(200)->get();
        foreach ($rows as $m) {
            if ($m->sender_id !== $u->id && !$m->read_at) {
                $m->update(['read_at' => now()]);
            }
        }
        return response()->json(['messages' => $rows->map(fn ($m) => Present::msg($m, $u))->all(), 'conversation' => Present::conv($cv, $u)]);
    }

    public function send(Request $r, int $cid)
    {
        if ($x = $this->limit($r, 'chat', 40)) {
            return $x;
        }
        $cv = $this->myConv($r, $cid);
        if (!$cv) {
            return $this->err('Not found.', 404);
        }
        $u = $this->who($r);
        if (!Billing::chatWindow($cv->patient_id, $cv->clinician_id)) {
            return $this->err($u->role === 'patient'
                ? 'This chat is closed. Book a new video + chat visit to message this doctor again.'
                : 'The chat window for this patient has closed.', 403);
        }
        $multipart = str_starts_with((string) $r->header('Content-Type'), 'multipart/form-data');
        $body = mb_substr(trim((string) ($multipart ? $r->input('body', '') : ($this->body($r)['body'] ?? ''))), 0, 2000);
        $m = null;
        if ($multipart && $r->file('photo')) {
            [$m, $err] = MediaStore::saveUpload($r->file('photo'), 'chat', true, $u->id);
            if ($err) {
                return $this->err($err);
            }
        }
        if ($body === '' && !$m) {
            return $this->err('Write a message or add a photo.');
        }
        $other = $u->role === 'patient' ? $cv->clinician->user : $cv->patient;
        $waiting = Message::where('conversation_id', $cid)->where('sender_id', $u->id)->whereNull('read_at')->count();
        $msg = Message::create(['conversation_id' => $cid, 'sender_id' => $u->id, 'body' => $body ?: null, 'media_id' => $m?->id, 'created_at' => now()]);
        if (!$waiting) {
            Notifier::notify($other, 'message', 'You have a new message.');   // one alert per unread run, not one per message
        }
        Audit::record('message_sent', (string) $cid);
        return response()->json(['message' => Present::msg($msg, $u)]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Audit;
use App\Models\Clinician;
use App\Models\Slot;
use App\Models\User;
use App\Services\Billing;
use App\Services\Cfg;
use App\Services\Notifier;
use App\Services\Present;
use App\Support\MediaStore;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ClinicianController extends Controller
{
    private function openSlotCount(int $id): int
    {
        return Slot::where('clinician_id', $id)->where('booked', false)->where('starts_at', '>', now())->count();
    }

    // ---- what patients see (signed in) ----
    public function index(Request $r)
    {
        Billing::expireHolds();
        $q = Clinician::with('user')->where('status', 'approved');   // unapproved doctors are never listed
        if ($s = $r->query('specialty')) {
            $q->where('specialty', 'like', '%' . addcslashes((string) $s, '%_\\') . '%');
        }
        return response()->json($q->get()->map(fn ($c) => Present::card($c, false, $this->openSlotCount($c->id)))->all());
    }

    public function show(int $id)
    {
        Billing::expireHolds();
        $c = Clinician::with('user')->find($id);
        if (!$c || $c->status !== 'approved') {
            return $this->err('This doctor is not available.', 404);
        }
        $seen = Appointment::where('clinician_id', $c->id)->where('status', 'completed')->count();
        return response()->json(Present::card($c, true, $this->openSlotCount($c->id)) + ['consultations' => $seen]);
    }

    public function slots(int $id)
    {
        Billing::expireHolds();
        $c = Clinician::find($id);
        if (!$c || $c->status !== 'approved') {
            return response()->json([]);
        }
        $rows = Slot::where('clinician_id', $id)->where('booked', false)->where('starts_at', '>', now())->orderBy('starts_at')->get();
        return response()->json($rows->map(fn ($x) => ['id' => $x->id, 'starts_at' => Present::iso($x->starts_at), 'duration_min' => $x->duration_min])->all());
    }

    // ---- what the landing page shows before sign-in ----
    public function publicDoctors()
    {
        $rows = Clinician::with('user')->where('status', 'approved')->orderBy('id')->limit(12)->get();
        return response()->json($rows->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->user->name, 'specialty' => $c->specialty, 'language' => $c->language, 'years_exp' => $c->years_exp,
            'photo_url' => Present::photoUrl($c), 'conditions' => array_slice(Present::conditions($c), 0, 4),
        ])->all());
    }

    public function prices()
    {
        return response()->json(['chat' => Billing::price('chat'), 'video_chat' => Billing::price('video_chat'), 'chat_days' => (int) Cfg::num('chat_days'), 'currency' => 'ETB']);
    }

    // ---- what a doctor does with their own profile and times ----
    public function profile(Request $r)
    {
        $c = $this->myClinician($r);
        if ($r->isMethod('put')) {
            $d = $this->body($r);
            if (array_key_exists('specialty', $d)) {
                $c->specialty = mb_substr(trim((string) $d['specialty']) ?: $c->specialty, 0, 80);
            }
            if (array_key_exists('language', $d)) {
                $c->language = mb_substr(trim((string) $d['language']), 0, 80);
            }
            if (array_key_exists('bio', $d)) {
                $c->bio = mb_substr(trim((string) $d['bio']), 0, 2000);
            }
            if (array_key_exists('conditions', $d)) {
                $raw = is_array($d['conditions']) ? $d['conditions'] : explode(',', (string) $d['conditions']);
                $c->conditions = mb_substr(implode(', ', array_filter(array_map(fn ($x) => trim((string) $x), $raw), fn ($x) => $x !== '')), 0, 400);
            }
            if (array_key_exists('years_exp', $d)) {
                $c->years_exp = trim((string) $d['years_exp']) !== '' ? max(0, min(80, (int) $d['years_exp'])) : null;
            }
            if (array_key_exists('reg_number', $d)) {
                $new = mb_substr(trim((string) $d['reg_number']), 0, 60);
                if ($new !== (string) $c->reg_number && $c->status === 'approved') {   // a new licence number must be checked again
                    $c->status = 'pending';
                    $c->submitted_at = now();
                }
                $c->reg_number = $new;
            }
            $c->save();
            Audit::record('profile_saved');
        }
        return response()->json(Present::profile($c));
    }

    public function photo(Request $r)
    {
        $c = $this->myClinician($r);
        [$m, $err] = MediaStore::saveUpload($r->file('photo'), 'profile', false, $this->who($r)->id);
        if ($err) {
            return $this->err($err);
        }
        MediaStore::discard($c->photo_id);
        $c->update(['photo_id' => $m->id]);
        Audit::record('profile_photo');
        return response()->json(['photo_url' => Present::photoUrl($c), 'missing' => Present::missing($c)]);
    }

    public function submit(Request $r)
    {
        $c = $this->myClinician($r);
        if (!in_array($c->status, ['draft', 'rejected'], true)) {
            return $this->err('Your profile is already submitted or approved.');
        }
        $miss = Present::missing($c);
        if ($miss) {
            return $this->err('Complete your profile first: ' . implode('; ', $miss) . '.', 400, ['missing' => $miss]);
        }
        $c->update(['status' => 'pending', 'submitted_at' => now()]);
        foreach (User::where('role', 'admin')->get() as $admin) {
            Notifier::notify($admin, 'doctor_review', 'A doctor profile is waiting for review.');
        }
        Audit::record('profile_submitted');
        return response()->json(Present::profile($c));
    }

    public function mySlots(Request $r)
    {
        $c = $this->myClinician($r);
        Billing::expireHolds();
        $rows = Slot::where('clinician_id', $c->id)->where('starts_at', '>', now())->orderBy('starts_at')->limit(300)->get();
        return response()->json($rows->map(fn ($x) => ['id' => $x->id, 'starts_at' => Present::iso($x->starts_at), 'duration_min' => $x->duration_min, 'booked' => (bool) $x->booked])->all());
    }

    public function addSlot(Request $r)
    {
        $c = $this->myClinician($r);
        if ($c->status !== 'approved') {
            return $this->err('Your credentials must be approved before you add availability.', 403);
        }
        try {
            $start = Carbon::parse((string) ($this->body($r)['starts_at'] ?? ''))->utc()->setMicroseconds(0);
        } catch (\Throwable) {
            return $this->err('Enter a valid date and time.');
        }
        if (Slot::where('clinician_id', $c->id)->where('starts_at', $start)->exists()) {
            return $this->err('You already have a slot at that time.', 409);
        }
        Slot::create(['clinician_id' => $c->id, 'starts_at' => $start, 'duration_min' => 30, 'booked' => false]);
        return response()->json(['ok' => true]);
    }

    public function deleteSlot(Request $r, int $id)
    {
        $c = $this->myClinician($r);
        $x = Slot::find($id);
        if (!$x || $x->clinician_id !== $c->id) {
            return $this->err('Not found.', 404);
        }
        if ($x->booked) {
            return $this->err('Someone has booked this time. Cancel the appointment instead.', 409);
        }
        $x->delete();
        return response()->json(['ok' => true]);
    }
}

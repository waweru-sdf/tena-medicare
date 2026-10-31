<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentPhoto;
use App\Models\Audit;
use App\Models\Clinician;
use App\Models\PatientProfile;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Billing;
use App\Services\Present;
use Illuminate\Http\Request;

/** The care team: many doctors per patient, many patients per doctor, plus the patient's saved details. */
class CareController extends Controller
{
    private function paidVisits(array $where)
    {
        return Appointment::with(['slot', 'clinician.user', 'patient'])->where($where)->whereIn('status', Billing::LIVE_OR_DONE)->get()->filter(fn ($a) => Billing::isPaid($a));
    }

    public function myDoctors(Request $r)
    {
        $u = $this->who($r);
        $out = [];
        foreach ($this->paidVisits(['patient_id' => $u->id])->groupBy('clinician_id') as $cid => $appts) {
            $c = Clinician::with('user')->find($cid);
            $out[] = Present::card($c) + Present::team($u, $u->id, (int) $cid, $appts);
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['last_visit'], (string) $a['last_visit']));
        return response()->json($out);
    }

    public function myPatients(Request $r)
    {
        $u = $this->who($r);
        $c = $this->myClinician($r);
        $out = [];
        foreach ($this->paidVisits(['clinician_id' => $c->id])->groupBy('patient_id') as $pid => $appts) {
            $out[] = ['id' => (int) $pid, 'name' => User::find($pid)->name] + Present::team($u, (int) $pid, $c->id, $appts);
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['last_visit'], (string) $a['last_visit']));
        return response()->json($out);
    }

    /** Everything this doctor may see about one patient: only the visits this doctor was paid for. */
    public function patientDetail(Request $r, int $pid)
    {
        $u = $this->who($r);
        $c = $this->myClinician($r);
        $appts = $this->paidVisits(['clinician_id' => $c->id, 'patient_id' => $pid]);
        if ($appts->isEmpty()) {
            return $this->err('This person is not one of your patients yet.', 404);
        }
        $p = User::find($pid);
        $visits = [];
        foreach ($appts->sortByDesc(fn ($a) => $a->slot->starts_at) as $a) {
            $visits[] = [
                'id' => $a->id, 'starts_at' => Present::iso($a->slot->starts_at), 'status' => $a->status, 'tier' => $a->tier ?: 'video', 'reason' => $a->reason,
                'photo_ids' => AppointmentPhoto::where('appointment_id', $a->id)->pluck('media_id')->all(),
                'prescriptions' => Prescription::where('appointment_id', $a->id)->get()->map(fn ($x) => Present::rx($x))->all(),
            ];
        }
        Audit::record('patient_opened', (string) $pid);
        return response()->json(Present::team($u, $pid, $c->id, $appts) + ['id' => $pid, 'name' => $p->name, 'profile' => Present::loadProfile($pid), 'visits' => $visits]);
    }

    /** Saved once, used for every visit with every doctor, so nobody starts from zero each time. */
    public function patientProfile(Request $r)
    {
        $u = $this->who($r);
        if ($r->isMethod('put')) {
            $d = $this->body($r);
            $data = [];
            foreach (Present::PROFILE_FIELDS as $k) {
                $data[$k] = mb_substr((string) ($d[$k] ?? ''), 0, 1500);
            }
            $row = PatientProfile::firstOrNew(['user_id' => $u->id]);
            $row->data = json_encode($data, JSON_UNESCAPED_UNICODE);
            $row->updated_at = now();
            $row->save();
            if (trim((string) ($d['name'] ?? '')) !== '') {
                $u->name = mb_substr(trim((string) $d['name']), 0, 120);
            }
            if (array_key_exists('phone', $d)) {
                $u->phone = mb_substr(trim((string) $d['phone']), 0, 30) ?: null;
            }
            $u->save();
            Audit::record('patient_profile_saved');
        }
        return response()->json(['profile' => Present::loadProfile($u->id), 'name' => $u->name, 'phone' => $u->phone, 'email' => $u->email, 'user' => Present::user($u)]);
    }
}

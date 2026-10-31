<?php

namespace App\Console\Commands;

use App\Models\Clinician;
use App\Models\Slot;
use App\Models\User;
use App\Support\MediaStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Makes the doctors on Tena the same people as on drfeyselclinic.com.
 * It reads the clinic website's public doctor list, so whatever staff edit there is what Tena gets.
 * Safe to run again at any time: it updates, it never duplicates.
 */
class ImportClinicDoctors extends Command
{
    protected $signature = 'tena:import-clinic-doctors
        {--url= : Clinic website address (default: CLINIC_SITE_URL or https://drfeyselclinic.com)}
        {--offline : Do not contact the website; use the names and photos shipped with Tena}
        {--slots : Also add sample open times for the next 5 days (testing only)}
        {--prune : Hide Tena doctors that are no longer on the clinic website}';
    protected $description = 'Copy the clinic website doctors (name, photo, bio, languages) into Tena';

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('url') ?: config('tena.clinic_site_url')), '/');
        $list = [];
        $live = false;
        if (!$this->option('offline')) {
            try {
                $r = Http::timeout(20)->acceptJson()->get("$base/api/site/doctors");
                if ($r->ok() && is_array($r->json())) {
                    $list = $r->json();
                    $live = true;
                }
            } catch (\Throwable $e) {
                $this->warn('Could not reach ' . $base . ' (' . $e->getMessage() . ').');
            }
        }
        if (!$live) {
            $list = json_decode((string) file_get_contents(base_path('database/clinic_doctors.json')), true) ?: [];
            $this->warn('Using the built-in list of doctors (names and photos only). Run again online to also get bios and languages.');
        }

        $seen = [];
        foreach ($list as $d) {
            $slug = (string) ($d['slug'] ?? '');
            if ($slug === '' || ($live && array_key_exists('accepts_telemedicine', $d) && !$d['accepts_telemedicine'])) {
                continue;
            }
            $seen[] = $slug;
            $full = $d;
            if ($live) {
                try {
                    $r = Http::timeout(20)->acceptJson()->get("$base/api/site/doctors/$slug");
                    $j = $r->ok() ? $r->json() : null;
                    $full = array_merge($d, is_array($j) ? ($j['doctor'] ?? $j) : []);
                } catch (\Throwable $e) {
                }
            }
            $this->upsert($slug, $full, $base, $live);
        }
        if ($this->option('prune') && $live) {
            $gone = Clinician::whereNotNull('source_slug')->whereNotIn('source_slug', $seen)->update(['status' => 'draft']);
            $this->info("Hid $gone doctor(s) no longer listed on the website.");
        }
        $this->info(count($seen) . ' doctor(s) are now the same as on the clinic website.');
        $this->line('Each doctor needs a real login: php artisan tena:set-doctor-login <slug> <email>');
        return self::SUCCESS;
    }

    private function upsert(string $slug, array $d, string $base, bool $live): void
    {
        $name = trim((string) ($d['name'] ?? Str::headline($slug)));
        $c = Clinician::where('source_slug', $slug)->first();
        if (!$c) {
            $u = User::create([
                'email' => "$slug@doctors.invalid",     // a placeholder nobody can receive; replaced by tena:set-doctor-login
                'name' => $name, 'role' => 'clinician', 'consent_version' => 'v1.0', 'consent_at' => now(),
                'password_hash' => Hash::make(Str::random(40)), 'token_version' => 1, 'mfa_on' => false,
            ]);
            $c = Clinician::create(['user_id' => $u->id, 'source_slug' => $slug, 'status' => 'approved', 'reg_number' => $d['registration_no'] ?? null]);
        } else {
            $c->user?->update(['name' => $name]);
        }
        $fill = ['status' => 'approved'];
        $title = trim((string) ($d['title'] ?? ''));
        if ($title !== '') {
            $fill['specialty'] = Str::limit($title, 80, '');
        }
        $subs = array_filter((array) ($d['subspecialties'] ?? []));
        if ($subs) {
            $fill['specialty'] = Str::limit(implode(', ', $subs), 80, '');
        }
        if (!empty($d['languages'])) {
            $fill['language'] = Str::limit(implode(', ', (array) $d['languages']), 80, '');
        }
        if (!empty($d['bio'])) {
            $fill['bio'] = (string) $d['bio'];
        }
        $conds = array_map(fn ($x) => is_array($x) ? ($x['name'] ?? '') : $x, (array) ($d['conditions'] ?? []));
        if (array_filter($conds)) {
            $fill['conditions'] = Str::limit(implode(', ', array_filter($conds)), 400, '');
        }
        if (isset($d['years_experience']) && $d['years_experience'] !== null) {
            $fill['years_exp'] = (int) $d['years_experience'];
        }
        $c->fill($fill)->save();

        $this->importPhoto($c, $slug, (string) ($d['photo'] ?? ''), $base, $live);

        if ($this->option('slots') && Slot::where('clinician_id', $c->id)->count() === 0) {
            for ($i = 1; $i <= 5; $i++) {
                foreach ([9, 11, 14] as $h) {
                    Slot::create(['clinician_id' => $c->id, 'starts_at' => now()->addDays($i)->setTime($h, 0, 0), 'duration_min' => 30, 'booked' => false]);
                }
            }
        }
        $this->line(" - $name");
    }

    private function importPhoto(Clinician $c, string $slug, string $path, string $base, bool $live): void
    {
        $raw = null;
        if ($live && $path !== '') {
            try {
                $r = Http::timeout(20)->get(Str::startsWith($path, 'http') ? $path : $base . '/' . ltrim($path, '/'));
                $raw = $r->ok() ? $r->body() : null;
            } catch (\Throwable $e) {
            }
        }
        if (!$raw) {
            $file = base_path("database/clinic_photos/$slug.jpg");
            $raw = is_file($file) ? (string) file_get_contents($file) : null;
        }
        if (!$raw) {
            return;
        }
        $old = $c->photo_id;
        [$m] = MediaStore::saveBytes($raw, 'profile', false, $c->user_id);
        if ($m) {
            $c->update(['photo_id' => $m->id]);
            MediaStore::discard($old);
        }
    }
}

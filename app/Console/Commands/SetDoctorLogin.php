<?php

namespace App\Console\Commands;

use App\Models\Audit;
use App\Models\Clinician;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetDoctorLogin extends Command
{
    protected $signature = 'tena:set-doctor-login {slug : e.g. zina-ahmed} {email} {--password= : Avoid this; leave it out to be asked privately}';
    protected $description = 'Give an imported doctor a real email and password so they can sign in to Tena';

    public function handle(): int
    {
        $c = Clinician::where('source_slug', $this->argument('slug'))->first();
        if (!$c) {
            $this->error('No imported doctor with that slug. Run tena:import-clinic-doctors first.');
            return self::FAILURE;
        }
        $email = strtolower(trim((string) $this->argument('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');
            return self::FAILURE;
        }
        if (User::where('email', $email)->where('id', '!=', $c->user_id)->exists()) {
            $this->error('Someone else already uses that email.');
            return self::FAILURE;
        }
        $password = (string) ($this->option('password') ?: $this->secret('Password (at least 12 characters)'));
        if (mb_strlen($password) < 12) {
            $this->error('Use at least 12 characters.');
            return self::FAILURE;
        }
        $u = $c->user;
        $u->email = $email;
        $u->password_hash = Hash::make($password);
        $u->token_version = (int) $u->token_version + 1;
        $u->save();
        Audit::record('doctor_login_set_by_console', $c->source_slug, $u->id);
        $this->info("Login saved for {$u->name} ({$email}).");
        return self::SUCCESS;
    }
}

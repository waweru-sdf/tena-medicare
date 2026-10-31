<?php

namespace App\Console\Commands;

use App\Models\Audit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'tena:create-admin {email?} {--role=admin : admin, support, finance or compliance} {--password= : Avoid this; leave it out to be asked privately}';
    protected $description = 'Create a staff login for Tena, or reset the password of an existing one';

    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->argument('email') ?: $this->ask('Email address'))));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');
            return self::FAILURE;
        }
        $role = (string) $this->option('role');
        if (!in_array($role, ['admin', 'support', 'finance', 'compliance'], true)) {
            $this->error('The role must be admin, support, finance or compliance.');
            return self::FAILURE;
        }
        $password = (string) ($this->option('password') ?: $this->secret('Password (at least 12 characters)'));
        if (mb_strlen($password) < 12) {
            $this->error('Use at least 12 characters.');
            return self::FAILURE;
        }
        if (!$this->option('password') && $password !== (string) $this->secret('Type the password again')) {
            $this->error('The passwords are not the same.');
            return self::FAILURE;
        }
        $user = User::firstOrNew(['email' => $email]);
        if ($user->exists && !in_array($user->role, ['admin', 'support', 'finance', 'compliance'], true)) {
            $this->error('That email belongs to a patient or doctor. Use a different one for staff.');
            return self::FAILURE;
        }
        $user->name = $user->name ?: 'Platform ' . ucfirst($role);
        $user->role = $role;
        $user->password_hash = Hash::make($password);
        $user->token_version = (int) $user->token_version + 1;   // signs out any older sessions of this account
        $user->save();
        Audit::record('staff_saved_by_console', $role, $user->id);
        $this->info("Staff login '{$email}' ({$role}) saved.");
        return self::SUCCESS;
    }
}

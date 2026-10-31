<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/** TEST DATA ONLY. Adds sample doctors and a sample patient so the site looks alive. Never run it on the real, public site. */
class Demo extends Command
{
    protected $signature = 'tena:demo {--yes : Really add the sample people}';
    protected $description = 'Add the clinic doctors with sample open times and a sample patient (testing only)';

    public function handle(): int
    {
        if (!$this->option('yes')) {
            $this->warn('This adds sample people with a well-known password (DemoPass123). Only for testing. Run again with --yes to continue.');
            return self::FAILURE;
        }
        $this->call('tena:import-clinic-doctors', ['--offline' => true, '--slots' => true]);
        if (!User::where('email', 'demopatient@example.com')->exists()) {
            User::create(['email' => 'demopatient@example.com', 'name' => 'Demo Patient', 'role' => 'patient', 'consent_version' => 'v1.0', 'consent_at' => now(), 'password_hash' => Hash::make('DemoPass123')]);
        }
        $this->info('Sample patient demopatient@example.com added (password DemoPass123).');
        return self::SUCCESS;
    }
}

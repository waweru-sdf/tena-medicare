<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Payment;
use App\Services\Billing;
use App\Services\Cfg;
use App\Services\Notifier;
use Illuminate\Console\Command;

class Tick extends Command
{
    protected $signature = 'tena:tick';
    protected $description = 'Every 5 minutes: free expired holds, send appointment reminders, check pending refunds';

    public function handle(): int
    {
        $n = Billing::expireHolds();
        $limit = now()->addHours((float) Cfg::num('reminder_hours'));
        $reminded = 0;
        $due = Appointment::with(['slot', 'patient'])->where('status', 'confirmed')->where('reminded', false)
            ->whereHas('slot', fn ($q) => $q->where('starts_at', '<=', $limit)->where('starts_at', '>', now()))->get();
        foreach ($due as $a) {
            Notifier::notify($a->patient, 'reminder', 'Reminder: you have an appointment coming up.');
            $a->update(['reminded' => true]);
            $reminded++;
        }
        foreach (Payment::where('status', 'refund_pending')->get() as $p) {
            Billing::syncRefund($p);
        }
        $this->info("Released {$n} holds, sent {$reminded} reminders.");
        return self::SUCCESS;
    }
}

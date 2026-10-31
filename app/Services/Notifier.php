<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notifications use generic wording only: no health details travel by email or SMS.
 * Sending happens after the reply is sent; a failure is recorded, never raised, so bookings are unaffected.
 */
class Notifier
{
    public static function notify(?User $u, string $kind, string $text): void
    {
        if (!$u) {
            return;
        }
        $body = $text . ' Sign in to Dr. Feysel Tena Medicine for details.';
        $row = Notification::create(['user_id' => $u->id, 'kind' => $kind, 'body' => $body, 'status' => 'logged', 'at' => now()]);
        $email = (string) $u->email;
        $phone = (string) $u->phone;
        $send = function () use ($row, $email, $phone, $body) {
            $status = 'logged';
            try {
                $sent = self::email($email, $body);
                if ($sent) {
                    $status = 'email_sent';
                }
                if (config('tena.at_api_key') && $phone) {
                    Http::timeout(15)->withHeaders(['apiKey' => config('tena.at_api_key'), 'Accept' => 'application/json'])->asForm()
                        ->post(config('tena.at_url'), ['username' => config('tena.at_username'), 'to' => $phone, 'message' => $body])->throw();
                    $status = $status === 'logged' ? 'sms_sent' : 'email_sms_sent';
                }
            } catch (\Throwable $e) {
                Log::warning('Notification failed: ' . $e->getMessage());
                $status = 'failed';
            }
            if ($status !== 'logged') {
                $row->update(['status' => $status]);
            }
        };
        if (app()->runningInConsole()) {
            $send();
        } else {
            app()->terminating($send);
        }
    }

    /** "Name <a@b.c>" -> [name, address] */
    public static function parseSender(string $sender): array
    {
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $sender, $m)) {
            return [$m[1], $m[2]];
        }
        return ['', $sender];
    }

    private static function email(string $to, string $body): bool
    {
        if (!$to) {
            return false;
        }
        [$name, $from] = self::parseSender((string) config('tena.mail_sender'));
        $brevo = (string) config('services.brevo.key');
        if ($brevo) {
            $res = Http::timeout(20)->withHeaders(['api-key' => $brevo, 'accept' => 'application/json'])->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => ['name' => $name ?: 'Dr. Feysel Tena Medicine', 'email' => $from], 'to' => [['email' => $to]],
                'subject' => 'Dr. Feysel Tena Medicine', 'textContent' => $body,
            ]);
            return $res->successful();
        }
        $c = config('tena.smtp');
        if (!$c['host']) {
            return false;
        }
        $mailer = Mail::build([
            'transport' => 'smtp', 'host' => $c['host'], 'port' => $c['port'],
            'encryption' => $c['ssl'] ? 'ssl' : ($c['tls'] ? 'tls' : null),
            'username' => $c['username'] ?: null, 'password' => $c['password'] ?: null, 'timeout' => 15,
        ]);
        $mailer->raw($body, fn ($m) => $m->from($from, $name ?: null)->to($to)->subject('Dr. Feysel Tena Medicine'));
        return true;
    }
}

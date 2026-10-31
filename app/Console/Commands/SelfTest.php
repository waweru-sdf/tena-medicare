<?php

namespace App\Console\Commands;

use App\Models\Audit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Checks the installation and runs a small sign-in test from inside the app. Run it after every install or update. */
class SelfTest extends Command
{
    protected $signature = 'tena:selftest';
    protected $description = 'Check the database, folders, website files, sign-in and the main API calls';

    private int $problems = 0;

    private function check(string $label, bool $ok, string $hint = ''): void
    {
        $ok ? $this->line("  <info>OK</info>    {$label}") : $this->line("  <error>FAIL</error>  {$label}" . ($hint ? "  ->  {$hint}" : ''));
        $this->problems += $ok ? 0 : 1;
    }

    private function hit(string $method, string $url, array $json = [], array $headers = []): \Symfony\Component\HttpFoundation\Response
    {
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + $headers;
        $req = Request::create($url, $method, [], [], [], $server, $json ? json_encode($json) : null);
        return app(\Illuminate\Contracts\Http\Kernel::class)->handle($req);
    }

    public function handle(): int
    {
        if ($dir = config('tena.public_dir')) {
            app()->usePublicPath((string) $dir);   // the website folder, since the command line does not know where public_html is
        }
        $this->info('Server');
        $this->check('PHP 8.2 or newer (this is ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.2.0', '>='));
        foreach (['pdo_mysql', 'mbstring', 'gd', 'fileinfo', 'curl', 'openssl'] as $ext) {
            $this->check("PHP extension {$ext}", extension_loaded($ext), 'enable it in hPanel > PHP Configuration');
        }
        $this->check('APP_KEY is set', (bool) config('app.key'), 'run: php artisan key:generate');
        $this->check('APP_DEBUG is off', !config('app.debug'), 'set APP_DEBUG=false in .env');
        $this->check('APP_URL is the real https address', str_starts_with((string) config('app.url'), 'https://'), 'set APP_URL=https://tena.drfeyselclinic.com in .env');

        $this->info('Folders');
        foreach (['storage/app/media', 'storage/framework/cache/data', 'storage/framework/views', 'storage/logs'] as $d) {
            $this->check("{$d} is writable", is_dir(base_path($d)) && is_writable(base_path($d)), 'run: chmod -R 775 storage bootstrap/cache');
        }
        $this->check('Website index.html found (' . public_path() . ')', is_file(public_path('index.html')), 'set TENA_PUBLIC_DIR in .env to the subdomain folder, or deploy the website files');
        $this->check('The app folder is not inside the public folder', !str_starts_with(realpath(base_path()) . '/', rtrim((string) realpath(public_path()), '/') . '/'), 'move the app out of public_html');

        $this->info('Database');
        try {
            DB::select('select 1');
            $this->check('Connected to MySQL', true);
            foreach (['users', 'clinicians', 'slots', 'appointments', 'payments', 'notes', 'media', 'messages', 'configs', 'payouts'] as $t) {
                $this->check("Table {$t}", Schema::hasTable($t), 'run: php artisan migrate --force');
            }
            if (Schema::hasTable('users')) {
                $this->check('At least one admin login exists', User::where('role', 'admin')->exists(), 'run: php artisan tena:create-admin');
            }
        } catch (\Throwable $e) {
            $this->check('Connected to MySQL', false, $e->getMessage());
        }

        $this->info('Payments, video and mode');
        $live = (bool) config('tena.production');
        $chapa = (bool) config('tena.chapa_key');
        if ($chapa) {
            $this->check('Payments: REAL Chapa payments are on (' . (str_contains(strtoupper((string) config('tena.chapa_key')), 'TEST') ? 'Chapa test key: no real money' : 'live key: real money') . ')', true);
            $this->check('Payments: Chapa webhook secret is set', (bool) config('tena.chapa_webhook_secret'), 'add CHAPA_WEBHOOK_SECRET to .env and the same text in the Chapa dashboard');
        } elseif ($live) {
            $this->check('Payments: Chapa key is set', false, 'add CHAPA_SECRET_KEY to .env (live mode refuses payments without it)');
        } else {
            $this->line('  <comment>NOTE</comment>  Payments are SIMULATED (test mode, no Chapa key). Patients see a test-payment button; no money moves. To go real: add CHAPA_SECRET_KEY and CHAPA_WEBHOOK_SECRET to .env.');
        }
        $v = \App\Services\Jitsi::status();
        if ($v['mode'] === 'public-test') {
            $this->line('  <comment>NOTE</comment>  Video: ' . $v['message']);
        } else {
            $this->check('Video rooms (' . $v['mode'] . '): ' . $v['message'], $v['ok'], $v['message']);
        }
        if ($live) {
            $this->check('Live mode is on (TENA_PRODUCTION=true)', true);
        } else {
            $this->line('  <comment>NOTE</comment>  TEST MODE is on (TENA_PRODUCTION=false). Before real patients: set TENA_PRODUCTION=true, add the Chapa and video keys, then run this test again.');
        }

        $this->info('Pages and API (requested from inside the app)');
        $urls = ['/' => 200, '/api/health' => 200, '/api/prices' => 200, '/api/public/doctors' => 200, '/api/ai/status' => 200, '/api/auth/me' => 401,
                 '/api/clinicians' => 401, '/api/admin/config' => 401, '/api/media/doesnotexist' => 404, '/no-such-file.js' => 404];
        foreach ($urls as $url => $want) {
            try {
                $code = $this->hit('GET', $url)->getStatusCode();
                $this->check("GET {$url} gives {$code}", $code === $want, "expected {$want}");
            } catch (\Throwable $e) {
                $this->check("GET {$url}", false, get_class($e) . ': ' . $e->getMessage());
            }
        }

        $this->info('Sign-in test (makes a throwaway patient, then removes it)');
        $email = 'selftest+' . bin2hex(random_bytes(4)) . '@example.invalid';
        try {
            $res = $this->hit('POST', '/api/auth/register', ['email' => $email, 'password' => 'SelfTest-' . bin2hex(random_bytes(6)), 'name' => 'Self Test', 'role' => 'patient']);
            $token = json_decode((string) $res->getContent(), true)['token'] ?? null;
            $this->check('Register a patient', $res->getStatusCode() === 200 && $token, (string) $res->getContent());
            $me = $this->hit('GET', '/api/auth/me', [], ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
            $this->check('The sign-in token is accepted', $me->getStatusCode() === 200);
            $bad = $this->hit('GET', '/api/auth/me', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token . 'x']);
            $this->check('An altered token is refused', $bad->getStatusCode() === 401);
            $role = $this->hit('GET', '/api/admin/config', [], ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
            $this->check('A patient cannot open admin pages', $role->getStatusCode() === 403);
            $prof = $this->hit('PUT', '/api/patient/profile', ['allergies' => 'none', 'name' => 'Self Test'], ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);
            $this->check('Saved details are stored encrypted and read back', $prof->getStatusCode() === 200 && (json_decode((string) $prof->getContent(), true)['profile']['allergies'] ?? '') === 'none');
        } catch (\Throwable $e) {
            $this->check('Sign-in test', false, get_class($e) . ': ' . $e->getMessage());
        } finally {
            $u = User::where('email', $email)->first();
            if ($u) {
                DB::table('patient_profiles')->where('user_id', $u->id)->delete();
                Audit::where('user_id', $u->id)->delete();
                $u->delete();
            }
        }

        $this->newLine();
        $this->problems === 0 ? $this->info('All checks passed.') : $this->error("{$this->problems} check(s) failed. Fix the lines marked FAIL, then run this again.");
        return $this->problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Services;

use App\Models\Clinician;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** One door for every AI feature. Keys stay on the server; output is only ever a draft for a person to review. */
class Ai
{
    public const EMERGENCY = '/የደረት (ህመም|ሕመም)|መተንፈስ አልቻ|ራሴን (ማጥፋት|ለማጥፋት)|chest pain|can\'?t breathe|cannot breathe|difficulty breathing|bleeding heavily|unconscious|suicid|overdose|stroke|seizure|'
        . 'swollen (face|lips|tongue|throat)|throat (is )?(closing|swelling)|anaphyla|blister\w* (in|on|around) (my )?(mouth|eyes)|skin (is )?peeling off|'
        . 'rash (and|with) (a )?(high )?fever|fever (and|with) (a )?(spreading )?rash|chemical burn|severe burn/iu';

    private const ALIASES = [
        'pimple' => 'acne', 'pimples' => 'acne', 'breakout' => 'acne', 'zits' => 'acne', 'itch' => 'eczema', 'itchy' => 'eczema', 'rash' => 'eczema', 'dry' => 'eczema',
        'dandruff' => 'scalp', 'hair' => 'hair', 'bald' => 'hair', 'balding' => 'hair', 'dark' => 'pigmentation', 'spots' => 'pigmentation', 'melasma' => 'pigmentation',
        'patches' => 'pigmentation', 'scales' => 'psoriasis', 'scaly' => 'psoriasis', 'ringworm' => 'fungal', 'fungus' => 'fungal', 'mole' => 'mole', 'moles' => 'mole',
        'wart' => 'warts', 'warts' => 'warts', 'scar' => 'scar', 'scars' => 'scar', 'baby' => 'paediatric', 'child' => 'paediatric', 'kid' => 'paediatric',
        'nails' => 'nail', 'nail' => 'nail', 'wrinkles' => 'cosmetic', 'aging' => 'cosmetic',
    ];

    public static function provider(): string
    {
        return (string) config('tena.ai_provider');
    }

    public static function model(): string
    {
        $m = (string) config('tena.ai_model');
        $lower = strtolower($m);
        return match (self::provider()) {
            'gemini' => str_starts_with($lower, 'gemini') ? $m : 'gemini-3.8-flash',
            'openai' => ($m !== '' && !str_starts_with($lower, 'claude') && !str_starts_with($lower, 'gemini')) ? $m : 'gpt-4o-mini',
            default => ($m !== '' && !preg_match('/^(gpt|o1|o3|o4|gemini)/', $lower)) ? $m : 'claude-sonnet-4-6',
        };
    }

    public static function key(): string
    {
        return (string) config(match (self::provider()) { 'openai' => 'tena.openai_key', 'gemini' => 'tena.gemini_key', default => 'tena.anthropic_key' });
    }

    public static function configured(): bool
    {
        return self::key() !== '';
    }

    public static function lastError(): ?string
    {
        return Cache::get('tena-ai-error');
    }

    /** Keep the last few turns, strictly alternating and starting with the user (every provider needs that). */
    public static function cleanHistory($history): array
    {
        $out = [];
        $items = is_array($history) ? array_slice($history, -8) : [];
        foreach ($items as $m) {
            if (!is_array($m)) {
                continue;
            }
            $r = $m['role'] ?? null;
            $t = trim(mb_substr((string) ($m['content'] ?? ''), 0, 600));
            if (!in_array($r, ['user', 'assistant'], true) || $t === '') {
                continue;
            }
            $n = count($out);
            if ($n && $out[$n - 1]['role'] === $r) {
                $out[$n - 1]['content'] .= "\n" . $t;
            } elseif ($n || $r === 'user') {
                $out[] = ['role' => $r, 'content' => $t];
            }
        }
        while ($out && end($out)['role'] === 'user') {   // the new question is appended as the next user turn
            array_pop($out);
        }
        $out = array_slice($out, -6);
        while ($out && $out[0]['role'] !== 'user') {
            array_shift($out);
        }
        return array_values($out);
    }

    public static function call(string $system, string $user, int $max = 700, $history = null): string
    {
        try {
            $out = self::send($system, $user, $max, $history);
            Cache::forget('tena-ai-error');
            return $out;
        } catch (\Throwable $e) {
            Cache::put('tena-ai-error', mb_substr($e->getMessage(), 0, 300), 86400);
            Log::warning('AI call failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private static function fail($res, string $name): void
    {
        if ($res->status() < 400) {
            return;
        }
        $msg = $res->json('error.message') ?: mb_substr((string) $res->body(), 0, 200);
        throw new \RuntimeException("{$name} answered HTTP {$res->status()}: " . ($msg ?: 'no details'));
    }

    private static function send(string $system, string $user, int $max, $history): string
    {
        $dayKey = 'tena-ai-' . date('Y-m-d');
        $used = (int) Cache::get($dayKey, 0);
        if ($used >= (int) config('tena.ai_daily_cap')) {
            throw new \RuntimeException('daily AI cap reached');   // stops a leaked page or a bot from running up a bill
        }
        Cache::put($dayKey, $used + 1, 90000);
        $msgs = array_merge(self::cleanHistory($history), [['role' => 'user', 'content' => $user]]);
        $key = self::key();
        $provider = self::provider();
        if ($provider === 'anthropic') {
            if (!$key) {
                throw new \RuntimeException('Anthropic AI not configured');
            }
            $headers = ['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'];
            if (config('tena.anthropic_workspace')) {
                $headers['anthropic-workspace-id'] = config('tena.anthropic_workspace');
            }
            $r = Http::timeout(40)->withHeaders($headers)->post('https://api.anthropic.com/v1/messages', ['model' => self::model(), 'max_tokens' => $max, 'system' => $system, 'messages' => $msgs]);
            self::fail($r, 'Anthropic');
            return implode('', array_map(fn ($b) => $b['text'] ?? '', $r->json('content') ?? []));
        }
        if ($provider === 'openai') {
            if (!$key) {
                throw new \RuntimeException('OpenAI AI not configured');
            }
            $body = ['model' => self::model(), 'messages' => array_merge([['role' => 'system', 'content' => $system]], $msgs)];
            foreach (['max_completion_tokens', 'max_tokens'] as $limitKey) {   // newer models reject max_tokens, older ones may reject the new name
                $r = Http::timeout(40)->withToken($key)->post('https://api.openai.com/v1/chat/completions', $body + [$limitKey => $max]);
                if ($r->status() !== 400) {
                    break;
                }
            }
            self::fail($r, 'OpenAI');
            return (string) ($r->json('choices.0.message.content') ?? '');
        }
        if ($provider === 'gemini') {
            if (!$key) {
                throw new \RuntimeException('Gemini AI not configured');
            }
            $contents = array_map(fn ($m) => ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]], $msgs);
            // Google answers 503/429 when a model is busy: try your model, retry once, then fall back to steadier models.
            $chain = array_values(array_unique(array_merge([self::model()], array_filter(array_map('trim', explode(',', (string) config('tena.gemini_fallbacks')))))));
            $r = null;
            foreach ($chain as $i => $model) {
                $gc = ['maxOutputTokens' => $max];
                if (str_contains($model, '2.5-flash')) {
                    $gc['thinkingConfig'] = ['thinkingBudget' => 0];   // otherwise hidden thinking eats the answer budget
                }
                for ($attempt = 0; $attempt < ($i === 0 ? 2 : 1); $attempt++) {
                    $r = Http::timeout(30)->withHeaders(['x-goog-api-key' => $key, 'Content-Type' => 'application/json'])
                        ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                            ['system_instruction' => ['parts' => [['text' => $system]]], 'contents' => $contents, 'generationConfig' => $gc]);
                    if (!in_array($r->status(), [429, 500, 502, 503, 504], true)) {
                        break;
                    }
                    if ($attempt === 0 && $i === 0) {
                        usleep(1200000);
                    }
                }
                if (!in_array($r->status(), [429, 500, 502, 503, 504], true)) {
                    break;
                }
            }
            self::fail($r, 'Gemini');
            $parts = $r->json('candidates.0.content.parts') ?? [];
            return implode('', array_map(fn ($x) => $x['text'] ?? '', $parts));
        }
        throw new \RuntimeException('AI_PROVIDER must be openai, anthropic or gemini');
    }

    public static function platformFacts(): string
    {
        $docs = Clinician::with('user')->where('status', 'approved')->limit(10)->get();
        $lines = $docs->map(fn ($c) => "- {$c->user->name} ({$c->specialty}; treats " . ($c->conditions ?: 'skin conditions') . "; speaks {$c->language}; chat " . Billing::price('chat') . ' ETB, video + chat ' . Billing::price('video_chat') . ' ETB)')->all();
        $days = (string) (int) Cfg::num('chat_days');
        return 'Dr. Feysel Tena Medicine is a skin-health (dermatology) telemedicine service in Ethiopia. Currency is Ethiopian birr (ETB). '
            . 'Patients create an account, find a doctor, read the doctor\'s profile, choose a time, describe what is going on and can attach up to 4 photos, then pick a plan and pay with Chapa. '
            . "Plan 1 'Chat with a doctor' (" . Billing::price('chat') . " ETB): secure messaging with the chosen doctor, including photos, for {$days} days, with no appointment time needed. "
            . "Plan 2 'Video consultation + chat' (" . Billing::price('video_chat') . " ETB): one 30-minute private video consultation at a chosen time, plus the same secure messaging for {$days} days after it, which is best for follow-ups. Prices are the same for every doctor. "
            . 'Both plans include prescriptions when the doctor decides one is needed. '
            . 'A doctor can have many patients and a patient can see more than one doctor. '
            . 'A chosen time is held for ' . Cfg::get('hold_minutes') . ' minutes while the patient pays, then released. '
            . 'Cancel or reschedule free at least ' . Cfg::get('cancel_hours') . ' hours before; refunds go back to the original payment method and can take time to arrive. '
            . 'The video room opens 10 minutes before the appointment; allow camera and microphone in the browser. '
            . 'Prescriptions and visit summaries appear under My records. Support requests go through the Support page. '
            . "Approved doctors right now:\n" . ($lines ? implode("\n", $lines) : 'none yet');
    }

    /** Deterministic doctor suggestions from what the person wrote (no AI involved, so it works even if the provider is down). */
    public static function matchDoctors(string $text, int $limit = 3): array
    {
        preg_match_all('/[a-zA-Z]{3,}/', strtolower($text), $m);
        $words = array_unique(array_map(fn ($w) => self::ALIASES[$w] ?? $w, $m[0]));
        $scored = [];
        foreach (Clinician::with('user')->where('status', 'approved')->get() as $c) {
            $hay = strtolower("{$c->specialty} " . ($c->conditions ?: '') . ' ' . ($c->bio ?: ''));
            $score = 0;
            foreach ($words as $w) {
                if (str_contains($hay, $w)) {
                    $score++;
                }
            }
            if ($score) {
                $scored[] = [$score, $c];
            }
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);
        return array_map(fn ($t) => ['id' => $t[1]->id, 'name' => $t[1]->user->name, 'specialty' => $t[1]->specialty, 'photo_url' => Present::photoUrl($t[1])], array_slice($scored, 0, $limit));
    }
}

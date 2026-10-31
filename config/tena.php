<?php

// Everything configurable for Tena Medicine, read from .env.
$bool = fn ($v, $d = false) => $v === null ? $d : filter_var($v, FILTER_VALIDATE_BOOLEAN);

return [
    // TEST MODE (TENA_PRODUCTION=false): payments are simulated and video can use open test rooms.
    // LIVE MODE (TENA_PRODUCTION=true): only real Chapa payments and private signed video rooms.
    'production' => $bool(env('TENA_PRODUCTION'), true),
    'token_days' => max(1, (int) env('TOKEN_DAYS', 14)),
    'hold_minutes' => max(1, (int) env('SLOT_HOLD_MINUTES', 15)),
    'enable_dev_payments' => $bool(env('ENABLE_DEV_PAYMENTS'), true),   // only has effect in test mode with no Chapa key
    'clinic_site_url' => rtrim((string) env('CLINIC_SITE_URL', 'https://drfeyselclinic.com'), '/'),

    'chapa_key' => (string) env('CHAPA_SECRET_KEY', ''),
    'chapa_webhook_secret' => (string) env('CHAPA_WEBHOOK_SECRET', ''),

    'ai_provider' => strtolower((string) (env('AI_PROVIDER') ?: (env('GEMINI_API_KEY') && !env('OPENAI_API_KEY') && !env('ANTHROPIC_API_KEY') ? 'gemini'
        : (env('ANTHROPIC_API_KEY') && !env('OPENAI_API_KEY') ? 'anthropic' : 'openai')))),
    'ai_model' => trim((string) env('AI_MODEL', '')),
    'ai_daily_cap' => (int) env('AI_DAILY_CAP', 1000),
    'openai_key' => (string) env('OPENAI_API_KEY', ''),
    'anthropic_key' => (string) env('ANTHROPIC_API_KEY', ''),
    'anthropic_workspace' => trim((string) env('ANTHROPIC_WORKSPACE_ID', '')),
    'gemini_key' => (string) env('GEMINI_API_KEY', ''),
    'gemini_fallbacks' => (string) env('GEMINI_FALLBACKS', 'gemini-2.5-flash,gemini-2.5-flash-lite'),
    'transcription_model' => (string) env('TRANSCRIPTION_MODEL', 'whisper-1'),

    'jitsi_domain' => (string) env('JITSI_DOMAIN', ''),
    'jitsi_app_id' => (string) env('JITSI_APP_ID', ''),
    'jitsi_app_secret' => (string) env('JITSI_APP_SECRET', ''),
    'jitsi_kid' => (string) env('JITSI_KID', ''),
    'jitsi_private_key' => str_replace('\n', "\n", (string) env('JITSI_PRIVATE_KEY', '')),
    'jitsi_private_key_file' => (string) env('JITSI_PRIVATE_KEY_FILE', ''),
    'allow_public_rooms' => $bool(env('ALLOW_PUBLIC_ROOMS'), true),   // only has effect in test mode

    // Email for notifications: Brevo (HTTP) or SMTP. SMS: Africa's Talking. All optional.
    'mail_sender' => (string) env('MAIL_SENDER', 'Dr. Feysel Tena Medicine <no-reply@drfeyselclinic.com>'),
    'smtp' => [
        'host' => (string) env('MAIL_SERVER', ''), 'port' => (int) env('MAIL_PORT', 587),
        'tls' => $bool(env('MAIL_USE_TLS'), true), 'ssl' => $bool(env('MAIL_USE_SSL'), false),
        'username' => (string) env('MAIL_USERNAME', ''), 'password' => (string) env('MAIL_PASSWORD', ''),
    ],
    'at_api_key' => (string) env('AT_API_KEY', ''),
    'at_username' => (string) env('AT_USERNAME', ''),
    'at_url' => (string) env('AT_URL', 'https://api.africastalking.com/version1/messaging'),

    'demo_data' => $bool(env('SEED_DEMO'), false),
    'public_dir' => env('TENA_PUBLIC_DIR'),
];

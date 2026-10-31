<?php

namespace App\Services;

use App\Models\Config;

/** Settings the admin changes in the website (prices, rules, switches), with safe defaults. */
class Cfg
{
    public static function defaults(): array
    {
        return [
            'price_chat' => '800', 'price_video_chat' => '1500', 'commission_pct' => '15', 'cancel_hours' => '12',
            'chat_days' => '30', 'reminder_hours' => '24', 'ai_assistant' => '1',
            'ai_notes' => '0', 'ai_intake' => '0', 'ai_translate' => '0', 'ai_ticket_drafts' => '0', 'ai_analytics' => '0',
            'hold_minutes' => (string) config('tena.hold_minutes'),
        ];
    }

    public const NUMERIC = ['price_chat', 'price_video_chat', 'commission_pct', 'cancel_hours', 'reminder_hours', 'hold_minutes', 'chat_days'];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = array_merge(self::defaults(), Config::pluck('value', 'key')->all());
        }
        return self::$cache;
    }

    public static function get(string $key): string
    {
        return (string) (self::all()[$key] ?? self::defaults()[$key] ?? '');
    }

    public static function num(string $key): float
    {
        return (float) self::get($key);
    }

    public static function set(string $key, string $value): void
    {
        Config::updateOrCreate(['key' => $key], ['value' => substr($value, 0, 200)]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}

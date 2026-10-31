<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Pictures live as files in storage/app/media (outside the web folder). Visit and chat photos are encrypted;
 * doctor profile photos are public on purpose, so they are stored plain.
 */
class MediaStore
{
    public const MAX_BYTES = 6 * 1024 * 1024;

    public static function sniff(string $raw): ?string
    {
        if (str_starts_with($raw, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($raw, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (substr($raw, 0, 4) === 'RIFF' && substr($raw, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }

    private static function path(string $id): string
    {
        return storage_path('app/media/' . $id);
    }

    /** @return array{0:?Media,1:?string} [media, error message] */
    public static function saveUpload(?UploadedFile $file, string $kind, bool $encrypt, ?int $ownerId): array
    {
        $raw = ($file && $file->isValid()) ? (string) file_get_contents($file->getRealPath()) : '';
        if ($raw === '') {
            return [null, 'Choose a photo first.'];
        }
        return self::saveBytes($raw, $kind, $encrypt, $ownerId);
    }

    /** @return array{0:?Media,1:?string} */
    public static function saveBytes(string $raw, string $kind, bool $encrypt, ?int $ownerId): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            return [null, 'That photo is too large. Photos must be under 6 MB.'];
        }
        $mime = self::sniff($raw);      // judged by content, not by file name or what the browser claims
        if (!$mime) {
            return [null, 'Only JPEG, PNG or WebP photos can be uploaded.'];
        }
        $id = Str::lower(Str::random(32));
        if (!is_dir(dirname(self::path($id)))) {
            mkdir(dirname(self::path($id)), 0775, true);
        }
        file_put_contents(self::path($id), $encrypt ? Crypt::encryptString($raw) : $raw);
        $m = Media::create(['id' => $id, 'owner_id' => $ownerId, 'kind' => $kind, 'mime' => $mime, 'encrypted' => $encrypt, 'created_at' => now()]);
        return [$m, null];
    }

    public static function read(Media $m): ?string
    {
        $p = self::path($m->id);
        if (!is_file($p)) {
            return null;
        }
        $raw = (string) file_get_contents($p);
        try {
            return $m->encrypted ? Crypt::decryptString($raw) : $raw;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function discard(?string $id): void
    {
        if (!$id) {
            return;
        }
        Media::where('id', $id)->delete();
        @unlink(self::path($id));
    }
}

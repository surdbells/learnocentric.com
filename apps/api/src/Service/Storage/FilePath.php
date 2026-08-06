<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * Normalises stored file references. The database holds a bare Flysystem path
 * (e.g. "2026/08/ab12cd.png"); the API exposes it as a backend-served route
 * ("/backend/files/2026/08/ab12cd.png"). External links a user legitimately
 * provides (e.g. a YouTube URL on a resource) are left untouched — only our
 * own uploaded-file references are pathified.
 */
final class FilePath
{
    /** Convert any incoming reference to the bare storage path we persist. */
    public static function toPath(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        // Our served route (query form: /backend/files?p=PATH, or legacy path form).
        if (preg_match('#[?&]p=([^&]+)#', $v, $m) === 1) {
            return self::clean(urldecode($m[1]));
        }
        if (preg_match('#^/?backend/files/(.+)$#', $v, $m) === 1) {
            return self::clean($m[1]);
        }
        // Our uploads location (absolute to any host, or root-relative).
        if (preg_match('#^(?:https?://[^/]+)?/?uploads/(.+)$#', $v, $m) === 1) {
            return self::clean($m[1]);
        }
        // Any other absolute URL is an external link (YouTube, Vimeo, …) — keep as-is.
        if (preg_match('#^https?://#', $v) === 1) {
            return $v;
        }
        // Already a bare path.
        return self::clean($v);
    }

    /** Convert a stored reference to something the browser can load. */
    public static function toUrl(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        // External link or legacy absolute URL — leave it.
        if (preg_match('#^https?://#', $v) === 1) {
            return $v;
        }
        // Already a served route.
        if (str_starts_with($v, '/backend/files')) {
            return $v;
        }
        return '/backend/files?p=' . ltrim($v, '/');
    }

    private static function clean(string $path): ?string
    {
        $p = ltrim(trim($path), '/');
        return $p === '' ? null : $p;
    }
}

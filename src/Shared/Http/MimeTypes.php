<?php

declare(strict_types=1);

namespace Fastsite\Shared\Http;

/**
 * Extension → Content-Type map for serving build output (preview + direct
 * serve). Extracted from PreviewServeHandler so both static servers agree.
 */
final class MimeTypes
{
    public const TYPES = [
        'html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript',
        'json' => 'application/json', 'xml' => 'application/xml', 'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'pdf' => 'application/pdf', 'zip' => 'application/zip', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'csv' => 'text/csv',
    ];

    public static function for(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::TYPES[$ext] ?? 'application/octet-stream';
    }
}

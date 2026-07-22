<?php

declare(strict_types=1);

namespace Fastsite\Media;

use Psr\Log\LoggerInterface;

/**
 * Used when SHORTPIXEL_API_KEY is unset — a pass-through. sharp still produces
 * AVIF/WebP variants, so images remain optimized; this just skips the extra
 * ShortPixel squeeze on the original. (Mirrors NullTranslator.)
 */
final class NullImageOptimizer implements ImageOptimizer
{
    private bool $warned = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function optimize(string $bytes, string $filename): string
    {
        if (!$this->warned) {
            $this->logger->info('ShortPixel not configured — images optimized by sharp only (set SHORTPIXEL_API_KEY to enable).');
            $this->warned = true;
        }

        return $bytes;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return 'disabled (no SHORTPIXEL_API_KEY)';
    }
}

<?php

declare(strict_types=1);

namespace Fastsite\Media;

/**
 * Optimizes raw image bytes on landing, before the sharp variant pipeline
 * reads them. Implementations: ShortPixelOptimizer (real) and
 * NullImageOptimizer (no-op when unconfigured). Must NEVER throw — a failed
 * optimization returns the input unchanged so an upload never fails on it.
 */
interface ImageOptimizer
{
    /** Returns optimized bytes, or the input unchanged on any problem. */
    public function optimize(string $bytes, string $filename): string;

    public function isConfigured(): bool;

    /** Human label for doctor output. */
    public function describe(): string;
}

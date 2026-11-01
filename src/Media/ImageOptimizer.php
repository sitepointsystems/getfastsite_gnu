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

    /**
     * Whether the LAST optimize() call failed (API error/timeout) rather than
     * legitimately finding no further gain. Callers tracking "optimized once"
     * state use this to leave a failed attempt retryable instead of marking
     * it done — optimize() itself still never throws.
     */
    public function lastAttemptFailed(): bool;

    /**
     * Why the last attempt failed (e.g. "ShortPixel -402: Wrong API Key."),
     * or null after a successful attempt. Surfaced in dashboard flashes and
     * logs so a failure is diagnosable without container access.
     */
    public function lastError(): ?string;

    public function isConfigured(): bool;

    /** Human label for doctor output. */
    public function describe(): string;
}

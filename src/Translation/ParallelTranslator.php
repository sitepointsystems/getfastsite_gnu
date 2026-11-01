<?php

declare(strict_types=1);

namespace Fastsite\Translation;

/**
 * A translator that can run many INDEPENDENT translations concurrently.
 *
 * Every field of every (content × locale) pair is an independent model call —
 * a title does not depend on a body, and Danish does not depend on German. Run
 * serially that independence is pure waste: an 82-page × 9-locale site is
 * ~1500 calls one after another, and the wall clock is the SUM of them.
 * {@see translateAll} fires a whole chunk over one curl_multi pool, so the
 * wall clock becomes roughly the slowest call per concurrency slot instead.
 *
 * Implementations must NEVER throw for a single failed request — a dead item
 * must not lose the whole batch's finished work. Per-request failures come
 * back as {@see TranslationException} VALUES in the result array, keyed like
 * the input; only a caller-level programming error throws.
 */
interface ParallelTranslator extends Translator
{
    /**
     * @param  array<string, array{content: string, from: string, to: string, format: string}>  $requests
     * @param  int  $concurrency  max in-flight calls (implementations clamp to a sane range)
     * @return array<string, string|TranslationException> keyed EXACTLY like $requests
     */
    public function translateAll(array $requests, int $concurrency = 4): array;
}

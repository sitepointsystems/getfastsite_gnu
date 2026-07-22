<?php

declare(strict_types=1);

namespace Fastsite\Translation\Repository;

use Fastsite\Translation\Dto\ContentTranslation;

interface TranslationRepository
{
    public function find(string $site, int $contentId, string $locale): ?ContentTranslation;

    /**
     * Translated (usable) rows for a locale, keyed by content_id.
     *
     * @return array<int, ContentTranslation>
     */
    public function usableByLocale(string $site, string $locale): array;

    /**
     * @param array<string, mixed> $fields
     */
    public function upsert(string $site, int $contentId, string $locale, array $fields): void;

    public function slugTaken(string $site, string $locale, string $slug, int $exceptContentId): bool;
}

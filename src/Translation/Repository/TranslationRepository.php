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
     * content_id → translated slug for a locale's usable rows — the build's
     * hreflang planning read. Deliberately slugs only: the hreflang clusters
     * need every locale up front, and holding every locale's FULL rows
     * (translated bodies included) for the whole build is part of what pushed
     * a large multilingual site's build into the memory ceiling. Full rows
     * load per locale (usableByLocale) inside the build loop and are freed
     * with it.
     *
     * @return array<int, string>
     */
    public function usableSlugsByLocale(string $site, string $locale): array;

    /**
     * EVERY row for a locale regardless of status, keyed by content_id — the
     * planning read for a sync run. One query per locale instead of a find()
     * per (content × locale): on a 82-page × 9-locale site that is 9 queries
     * a chunk instead of ~740, and every chunk paid the old price just to
     * discover that most items were already current.
     *
     * @return array<int, ContentTranslation>
     */
    public function allByLocale(string $site, string $locale): array;

    /**
     * @param array<string, mixed> $fields
     */
    public function upsert(string $site, int $contentId, string $locale, array $fields): void;

    public function slugTaken(string $site, string $locale, string $slug, int $exceptContentId): bool;
}

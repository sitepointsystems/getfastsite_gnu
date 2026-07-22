<?php

declare(strict_types=1);

namespace Fastsite\Translation\Repository;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Translation\Dto\ContentTranslation;

final class DbalTranslationRepository implements TranslationRepository
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function find(string $site, int $contentId, string $locale): ?ContentTranslation
    {
        $row = $this->databases->site($site)->fetchAssociative(
            'SELECT * FROM content_translations WHERE content_id = ? AND locale = ?',
            [$contentId, $locale],
        );

        return $row === false ? null : ContentTranslation::fromRow($row);
    }

    public function usableByLocale(string $site, string $locale): array
    {
        $rows = $this->databases->site($site)->fetchAllAssociative(
            "SELECT * FROM content_translations WHERE locale = ? AND status IN ('translated', 'manual')",
            [$locale],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['content_id']] = ContentTranslation::fromRow($row);
        }

        return $out;
    }

    public function upsert(string $site, int $contentId, string $locale, array $fields): void
    {
        $db = $this->databases->site($site);
        $now = $this->clock->iso();

        $existing = $db->fetchOne(
            'SELECT id FROM content_translations WHERE content_id = ? AND locale = ?',
            [$contentId, $locale],
        );

        if ($existing !== false) {
            $fields['updated_at'] = $now;
            $db->update('content_translations', $fields, ['id' => (int) $existing]);

            return;
        }

        $db->insert('content_translations', $fields + [
            'content_id' => $contentId,
            'locale' => $locale,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function slugTaken(string $site, string $locale, string $slug, int $exceptContentId): bool
    {
        return (bool) $this->databases->site($site)->fetchOne(
            'SELECT 1 FROM content_translations WHERE locale = ? AND slug = ? AND content_id != ?',
            [$locale, $slug, $exceptContentId],
        );
    }
}

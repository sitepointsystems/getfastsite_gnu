<?php

declare(strict_types=1);

namespace Fastsite\Site\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\SiteScript;

/**
 * User-managed header/footer scripts. Trusted, owner-curated content — the
 * `code` is injected verbatim at build (a bare URL becomes a <script src>
 * with the chosen strategy). A site with any enabled script opts out of the
 * zero-JS / 100-PageSpeed guarantee for that site (build relaxes CSP + audit).
 */
final class SiteScriptService
{
    private const LOCATIONS = ['head', 'body_open', 'body_end'];
    private const STRATEGIES = ['blocking', 'async', 'defer'];

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<SiteScript>
     */
    public function list(string $handle, bool $onlyEnabled = false): array
    {
        $sql = 'SELECT * FROM site_scripts';
        if ($onlyEnabled) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY location, priority, id';

        return array_map(SiteScript::fromRow(...), $this->databases->site($handle)->fetchAllAssociative($sql));
    }

    /** True when any script is enabled — the signal to relax CSP + PageAudit. */
    public function hasEnabled(string $handle): bool
    {
        try {
            return (bool) $this->databases->site($handle)->fetchOne('SELECT 1 FROM site_scripts WHERE enabled = 1 LIMIT 1');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(string $handle, array $input): SiteScript
    {
        $location = (string) ($input['location'] ?? 'head');
        if (!\in_array($location, self::LOCATIONS, true)) {
            throw new \InvalidArgumentException('location must be one of: ' . implode(', ', self::LOCATIONS));
        }
        $strategy = (string) ($input['strategy'] ?? 'defer');
        if (!\in_array($strategy, self::STRATEGIES, true)) {
            throw new \InvalidArgumentException('strategy must be one of: ' . implode(', ', self::STRATEGIES));
        }
        $code = (string) ($input['code'] ?? '');
        if (mb_strlen($code) > 100_000) {
            throw new \InvalidArgumentException('Script is too large (100 KB limit).');
        }

        // Read once: `$input['enabled'] ?? true` only guards the FIRST operand —
        // the later !== comparisons hit the missing key and warn on every save
        // that omits it.
        $enabled = $input['enabled'] ?? true;

        $fields = [
            'label' => mb_substr(trim((string) ($input['label'] ?? '')), 0, 200),
            'location' => $location,
            'strategy' => $strategy,
            'priority' => (int) ($input['priority'] ?? 100),
            'code' => $code,
            'enabled' => ($enabled && $enabled !== '0' && $enabled !== 'false') ? 1 : 0,
            'updated_at' => $this->clock->iso(),
        ];

        $db = $this->databases->site($handle);
        $id = (int) ($input['id'] ?? 0);
        if ($id > 0 && $db->fetchOne('SELECT 1 FROM site_scripts WHERE id = ?', [$id]) !== false) {
            $db->update('site_scripts', $fields, ['id' => $id]);
        } else {
            $fields['created_at'] = $this->clock->iso();
            $db->insert('site_scripts', $fields);
            $id = (int) $db->lastInsertId();
        }

        $row = $db->fetchAssociative('SELECT * FROM site_scripts WHERE id = ?', [$id]);
        \assert($row !== false);

        return SiteScript::fromRow($row);
    }

    public function delete(string $handle, int $id): void
    {
        $this->databases->site($handle)->executeStatement('DELETE FROM site_scripts WHERE id = ?', [$id]);
    }

    /**
     * Rendered HTML per injection location (enabled only, in order). A bare URL
     * becomes a <script src> with async/defer; anything else is injected as-is.
     *
     * @return array{head: string, body_open: string, body_end: string}
     */
    public function renderByLocation(string $handle): array
    {
        $out = ['head' => '', 'body_open' => '', 'body_end' => ''];
        foreach ($this->list($handle, true) as $script) {
            $out[$script->location] .= self::render($script) . "\n";
        }

        return $out;
    }

    private static function render(SiteScript $script): string
    {
        $code = trim($script->code);
        // A bare URL (no markup) → a <script src> honoring the strategy.
        if ($code !== '' && !str_contains($code, '<') && preg_match('#^https?://\S+$#', $code) === 1) {
            $attr = $script->strategy === 'async' ? ' async' : ($script->strategy === 'defer' ? ' defer' : '');
            return '<script src="' . htmlspecialchars($code, ENT_QUOTES) . '"' . $attr . '></script>';
        }

        // Otherwise inject verbatim (a pasted GA/pixel snippet, a <noscript>…).
        return $code;
    }
}

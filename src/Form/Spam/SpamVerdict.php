<?php

declare(strict_types=1);

namespace Fastsite\Form\Spam;

/**
 * What the scorer concluded about one submission, and why.
 *
 * The reasons are kept and stored, not just the number. A score on its own is
 * unarguable and therefore useless when someone asks why a real enquiry went
 * to the spam folder — "2 links in a message field, sender domain has no MX"
 * is a thing you can check and disagree with.
 */
final readonly class SpamVerdict
{
    /**
     * @param list<string> $reasons human-readable, shown in the dashboard
     */
    public function __construct(
        public int $score,
        public array $reasons,
        public bool $isSpam,
    ) {
    }

    public static function clean(): self
    {
        return new self(0, [], false);
    }

    /** @return list<string> */
    public function reasons(): array
    {
        return $this->reasons;
    }

    public function reasonsJson(): string
    {
        $json = json_encode($this->reasons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '[]' : $json;
    }
}

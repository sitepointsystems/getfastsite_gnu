<?php

declare(strict_types=1);

namespace Fastsite\FactCheck;

/**
 * The result of a fact-check: an overall summary plus per-claim verdicts with
 * sources. Verdicts are one of supported | contradicted | unverified.
 */
final readonly class FactCheckReport
{
    /**
     * @param list<array{claim:string, verdict:string, confidence:string, note:string, sources:list<string>}> $claims
     */
    public function __construct(
        public string $summary,
        public array $claims,
        public string $model = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['summary' => $this->summary, 'claims' => $this->claims, 'model' => $this->model];
    }

    /**
     * Build from the model's JSON, coercing every field so a sloppy response
     * can never produce a malformed report.
     *
     * @param array<string, mixed> $data
     */
    public static function fromJson(array $data, string $model): self
    {
        $claims = [];
        foreach ((array) ($data['claims'] ?? []) as $c) {
            if (!\is_array($c)) {
                continue;
            }
            $verdict = (string) ($c['verdict'] ?? 'unverified');
            $claims[] = [
                'claim' => (string) ($c['claim'] ?? ''),
                'verdict' => \in_array($verdict, ['supported', 'contradicted', 'unverified'], true) ? $verdict : 'unverified',
                'confidence' => (string) ($c['confidence'] ?? ''),
                'note' => (string) ($c['note'] ?? ''),
                'sources' => array_values(array_filter(array_map(
                    static fn ($s): string => (string) $s,
                    (array) ($c['sources'] ?? []),
                ), static fn (string $s): bool => $s !== '')),
            ];
        }

        return new self((string) ($data['summary'] ?? ''), $claims, $model);
    }
}

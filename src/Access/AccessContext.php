<?php

declare(strict_types=1);

namespace Fastsite\Access;

/**
 * ONE authorization model for every transport (API key now; OAuth token and
 * web session join in M9/M11). Attached to the request by auth middleware.
 */
final readonly class AccessContext
{
    public const REQUEST_ATTRIBUTE = 'access';

    /**
     * @param list<string> $scopes
     * @param list<string> $sites site handles, or ["*"]
     */
    public function __construct(
        public string $actorType,
        public int $userId,
        public ?int $apiKeyId,
        public array $scopes,
        public array $sites,
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return \in_array('admin', $this->scopes, true) || \in_array($scope, $this->scopes, true);
    }

    public function allowsSite(string $handle): bool
    {
        return \in_array('*', $this->sites, true) || \in_array($handle, $this->sites, true);
    }
}

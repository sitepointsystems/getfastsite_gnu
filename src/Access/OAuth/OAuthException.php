<?php

declare(strict_types=1);

namespace Fastsite\Access\OAuth;

/**
 * An OAuth error with an RFC 6749 error code (invalid_grant, invalid_request,
 * etc.) for the token/authorize error responses.
 */
final class OAuthException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $description)
    {
        parent::__construct($description);
    }
}

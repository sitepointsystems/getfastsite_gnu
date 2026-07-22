<?php

declare(strict_types=1);

namespace Fastsite\Content\Service;

/**
 * A (source, source_ref) collision — the same external content was ingested
 * twice. Maps to HTTP 409, not a 500.
 */
final class DuplicateContentException extends \RuntimeException
{
}

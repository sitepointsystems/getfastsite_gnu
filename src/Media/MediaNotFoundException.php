<?php

declare(strict_types=1);

namespace Fastsite\Media;

/**
 * The addressed media row does not exist (or is not visible) on this site.
 *
 * Its own type so transports can answer 404 rather than folding it into the
 * generic RuntimeException catch, where "no such id" came back as 502 Bad
 * Gateway — a status that says "upstream is broken, retry later" about a
 * request that can never succeed.
 */
final class MediaNotFoundException extends \RuntimeException
{
}

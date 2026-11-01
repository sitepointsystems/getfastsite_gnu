<?php

declare(strict_types=1);

namespace Fastsite\Form\Spam;

/**
 * "Can this domain receive mail at all?" — a seam, so the scorer stays pure
 * and its tests never touch the network.
 */
interface MxResolver
{
    public function hasMx(string $domain): bool;
}

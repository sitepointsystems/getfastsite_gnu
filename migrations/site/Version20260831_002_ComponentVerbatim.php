<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Fastsite\Shared\Database\Migration;

/**
 * Per-component verbatim flag: the component's rendered output ships AS
 * AUTHORED — scripts, iframes, event handlers and all — wrapped in the same
 * `<div data-fs-embed>` trusted-region marker verbatim content and file
 * embeds already use, instead of being run through the renderer's
 * active-content strip.
 *
 * Off by default: a component keeps the zero-JS guarantee unless its author
 * explicitly trades it away, exactly like `sites.sanitize_content` and the
 * site-scripts opt-in. The flag rides in the render cache key (same source,
 * different flag = different output) and in the preview staleness signature.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->executeStatement('ALTER TABLE components ADD COLUMN verbatim INTEGER NOT NULL DEFAULT 0');
    }
};

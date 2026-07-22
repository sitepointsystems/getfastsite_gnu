<?php

declare(strict_types=1);

namespace Fastsite\Webhook\Mapper;

/** Built-in system webhook: a payload → a new page. */
final class CreatePageMapper extends AbstractContentMapper
{
    public static function key(): string
    {
        return 'create_page';
    }

    protected function type(): string
    {
        return 'page';
    }
}

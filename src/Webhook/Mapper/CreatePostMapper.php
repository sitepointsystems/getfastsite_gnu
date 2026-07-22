<?php

declare(strict_types=1);

namespace Fastsite\Webhook\Mapper;

/** Built-in system webhook: a payload → a new post. */
final class CreatePostMapper extends AbstractContentMapper
{
    public static function key(): string
    {
        return 'create_post';
    }

    protected function type(): string
    {
        return 'post';
    }
}

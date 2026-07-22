<?php

declare(strict_types=1);

namespace Fastsite\Shared\Fs;

final class Dirs
{
    /**
     * Removes empty directories under $root (deepest first), never touching
     * $skip directories (e.g. .git) or $root itself.
     */
    public static function pruneEmpty(string $root, string $skip = '.git'): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => $f->getFilename() !== $skip,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);
            if ($item->isDir() && scandir($item->getPathname()) === ['.', '..']) {
                rmdir($item->getPathname());
            }
        }
    }
}

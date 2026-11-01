<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Twig\Loader\FilesystemLoader;

/**
 * Loads a site's shell templates, keyed by their CONTENT rather than by path.
 *
 * Twig names the PHP class it compiles a template into after the loader's cache
 * key, and with `cache => false` it skips recompiling whenever that class is
 * already declared — a check that is process-global, not per-Environment.
 * FilesystemLoader's cache key is the template PATH, so inside one long-running
 * process (a `queue:work` worker) the first build of a site pinned its
 * templates forever: every later build in that worker re-used the classes
 * compiled from the OLD source, and edits to the shell simply never appeared in
 * the output. The build reported `written=0` and the publish committed nothing,
 * so the pipeline looked healthy while quietly shipping a stale design.
 *
 * It also explains why previewing "fixed" it: preview builds inside a php-fpm
 * request — a fresh process, with no classes declared yet — so it always
 * rendered the new templates and left a correct build/ tree behind for the
 * publish to push.
 *
 * Hashing the source into the key makes the class identity follow the content:
 * changed template, new class, fresh compile; unchanged template, same class,
 * same fast path as before.
 */
final class ShellTemplateLoader extends FilesystemLoader
{
    public function getCacheKey(string $name): string
    {
        // md5 is a cache key here, never a security boundary. Templates are
        // small and few, and Twig asks once per template per Environment.
        return parent::getCacheKey($name) . ':' . md5($this->getSourceContext($name)->getCode());
    }
}

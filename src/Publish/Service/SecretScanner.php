<?php

declare(strict_types=1);

namespace Fastsite\Publish\Service;

use Psr\Log\LoggerInterface;

/**
 * Pre-publish secret guard + build-tree enumeration, shared by every publish
 * transport. A dist pushed to a git remote and a dist uploaded to a customer's
 * FTP host are equally public, so the same scan has to run before both —
 * extracted from GitPublisher rather than copied so the two can never drift.
 *
 * The two-tier rule (learned from a prior IP leak) is the whole point: a PEM
 * private key is unambiguous and ALWAYS blocks the publish, while the
 * shape-based patterns (API keys, AWS ids, env assignments) block only on
 * engine-generated deploy artifacts (_headers, _redirects, robots.txt,
 * sitemaps, .htaccess) where a secret is a pure pipeline bug. On RENDERED
 * CONTENT PAGES they only WARN, because a blog post may legitimately mention
 * "APP_SECRET=" or an fs_-shaped string and content must never wedge the
 * pipeline.
 */
final class SecretScanner
{
    /**
     * Unencrypted + encrypted PEM private-key headers; never legitimate in
     * shipped output, so this one blocks everywhere.
     */
    private const PEM_PATTERN = '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |PGP |ENCRYPTED |SSH2 ENCRYPTED )?PRIVATE KEY-----/';

    /**
     * Anchor-free so a minified/embedded assignment is still caught; the
     * 8+ char value cuts noise. Applied only to non-content files.
     */
    private const SHAPE_PATTERNS = [
        'fastsite API key' => '/fs_[A-Za-z0-9]{8}_[A-Za-z0-9]{16,}/',
        'AWS access key id' => '/\bAKIA[0-9A-Z]{16}\b/',
        'env secret assignment' => '/(?:APP_SECRET|R2_SECRET_KEY|ENDPOINTR_KEY|GITHUB_TOKEN|REDIS_URL)\s*[=:]\s*["\']?\S{8,}/i',
    ];

    /** AWS publishes this exact key in its own docs; never a real secret. */
    private const AWS_EXAMPLE = 'AKIAIOSFODNN7EXAMPLE';

    private const BINARY_EXTENSIONS = ['avif', 'webp', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'woff2', 'woff', 'ico', 'mp4', 'webm', 'zip', 'pdf'];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Every file under $root as sorted, POSIX-normalized relative paths.
     * Dotfiles are INCLUDED (SKIP_DOTS only drops "." and ".."), which is what
     * carries the generated .htaccess into an FTP publish.
     *
     * @return list<string>
     */
    public function relativeFiles(string $root, bool $skipGit = false): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !($skipGit && $f->getFilename() === '.git'),
            ),
        );

        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param string $what how the destination is described in the abort message
     *                     ("Nothing was pushed." / "Nothing was uploaded.")
     *
     * @throws \RuntimeException when the tree must not be published
     */
    public function assertNoSecrets(string $dir, bool $skipGit = true, string $what = 'Nothing was pushed.'): void
    {
        foreach ($this->relativeFiles($dir, skipGit: $skipGit) as $relative) {
            $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (\in_array($ext, self::BINARY_EXTENSIONS, true)) {
                continue; // binary/media can't carry a readable secret
            }
            $content = (string) @file_get_contents($dir . '/' . $relative);
            if ($content === '') {
                continue;
            }

            if (preg_match(self::PEM_PATTERN, $content) === 1) {
                throw new \RuntimeException(sprintf(
                    'Pre-push secret scan aborted the publish: private key material in build output "%s". %s',
                    $relative,
                    $what,
                ));
            }

            $isContentPage = $ext === 'html' || $ext === 'htm';
            foreach (self::SHAPE_PATTERNS as $label => $pattern) {
                if ($label === 'AWS access key id' && str_contains($content, self::AWS_EXAMPLE)) {
                    continue; // documented example, not a real key
                }
                if (preg_match($pattern, $content) !== 1) {
                    continue;
                }
                if ($isContentPage) {
                    // Content must never wedge the pipeline — log and move on.
                    $this->logger->warning('Pre-push secret scan: suspicious string on a content page (published anyway)', [
                        'file' => $relative,
                        'pattern' => $label,
                    ]);
                    continue;
                }
                throw new \RuntimeException(sprintf(
                    'Pre-push secret scan aborted the publish: possible %s in engine output "%s". %s',
                    $label,
                    $relative,
                    $what,
                ));
            }
        }
    }
}

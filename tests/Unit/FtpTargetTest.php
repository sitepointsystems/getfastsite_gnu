<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Publish\Service\FtpTarget;
use PHPUnit\Framework\TestCase;

/**
 * Path and URL construction for uploads to a customer's own host. Every one of
 * these is a case where getting it wrong writes files to the wrong place on
 * someone else's server, which is not a failure mode a fake transport can
 * catch.
 */
final class FtpTargetTest extends TestCase
{
    private function target(string $protocol = 'sftp', int $port = 0, string $path = '/public_html'): FtpTarget
    {
        return new FtpTarget($protocol, 'host.example', $port, 'deploy', 'pw', '', $path);
    }

    public function testPortZeroResolvesToTheProtocolDefault(): void
    {
        self::assertSame(22, $this->target('sftp')->effectivePort());
        self::assertSame(21, $this->target('ftp')->effectivePort());
        self::assertSame(21, $this->target('ftps')->effectivePort());
        self::assertSame(2222, $this->target('sftp', 2222)->effectivePort());
    }

    /** FTPS is explicit TLS over the ftp:// scheme, not an ftps:// URL. */
    public function testFtpsUsesTheFtpScheme(): void
    {
        self::assertSame('ftp', $this->target('ftps')->scheme());
        self::assertSame('ftp', $this->target('ftp')->scheme());
        self::assertSame('sftp', $this->target('sftp')->scheme());
    }

    public function testUrlForBuildsANormalizedAbsoluteUrl(): void
    {
        $t = $this->target();
        self::assertSame('sftp://host.example:22/public_html/blog/post/index.html', $t->urlFor('blog/post/index.html'));
        self::assertSame('sftp://host.example:22/public_html/index.html', $t->urlFor('/index.html'));
        self::assertSame('sftp://host.example:22/public_html/', $t->urlFor(''));
    }

    /**
     * Quote commands (DELE / rm) run BEFORE curl's CWD, so the path must be
     * qualified with the remote directory rather than relative to the URL.
     */
    public function testRemoteFileQualifiesWithTheRemoteDirectory(): void
    {
        self::assertSame('/public_html/blog/index.html', $this->target()->remoteFile('blog/index.html'));
        self::assertSame('/public_html/blog/index.html', $this->target()->remoteFile('/blog/index.html'));
    }

    /**
     * With no remote directory the site publishes into the login directory —
     * a leading slash there would address the SERVER'S filesystem root.
     */
    public function testRemoteFileStaysRelativeWhenPublishingToTheLoginDirectory(): void
    {
        self::assertSame('blog/index.html', $this->target('sftp', 0, '')->remoteFile('blog/index.html'));
        self::assertSame('index.html', $this->target('sftp', 0, '')->remoteFile('/index.html'));
    }

    public function testRemotePathIsNormalized(): void
    {
        self::assertSame('/public_html', FtpTarget::normalizePath('/public_html/'));
        self::assertSame('/public_html', FtpTarget::normalizePath('  /public_html//  '));
        // Hosting panels hand people Windows-looking paths.
        self::assertSame('/public_html/site', FtpTarget::normalizePath('\\public_html\\site\\'));
        self::assertSame('', FtpTarget::normalizePath('/'));
    }

    /**
     * The manifest is discarded when this changes, so it must track everything
     * that decides WHICH machine and directory we are writing to — and nothing
     * else, or a routine password rotation would force a full re-upload.
     */
    public function testTargetKeyTracksTheDestinationButNotTheCredentials(): void
    {
        $base = $this->target();

        self::assertSame($base->targetKey(), $this->target()->targetKey());
        self::assertSame(
            $base->targetKey(),
            (new FtpTarget('sftp', 'host.example', 0, 'deploy', 'ROTATED', 'a-new-key', '/public_html'))->targetKey(),
            'Rotating a password or key is not a new destination.',
        );
        // An explicit 22 and the implied default are the same machine.
        self::assertSame($base->targetKey(), $this->target('sftp', 22)->targetKey());

        foreach ([
            'host' => new FtpTarget('sftp', 'other.example', 0, 'deploy', 'pw', '', '/public_html'),
            'port' => $this->target('sftp', 2222),
            'user' => new FtpTarget('sftp', 'host.example', 0, 'other', 'pw', '', '/public_html'),
            'path' => $this->target('sftp', 0, '/www'),
            'protocol' => $this->target('ftps'),
        ] as $what => $changed) {
            self::assertNotSame($base->targetKey(), $changed->targetKey(), "A changed {$what} is a different destination.");
        }
    }

    public function testCertificateVerificationIsOnByDefault(): void
    {
        self::assertTrue($this->target()->verifyCertificate);
    }

    /**
     * Turning verification off is a trust decision, not a change of
     * destination — it must not discard the upload manifest and force a full
     * re-upload of the whole site.
     */
    public function testCertificateChoiceIsNotPartOfTheTargetKey(): void
    {
        $verifying = new FtpTarget('ftps', 'host.example', 0, 'deploy', 'pw', '', '/public_html', true);
        $trusting = new FtpTarget('ftps', 'host.example', 0, 'deploy', 'pw', '', '/public_html', false);

        self::assertSame($verifying->targetKey(), $trusting->targetKey());
    }

    /** Shown in flashes and logs — it must never carry the password. */
    public function testDescribeNeverLeaksTheCredentials(): void
    {
        $described = $this->target()->describe();

        self::assertStringNotContainsString('pw', $described);
        self::assertStringContainsString('host.example', $described);
        self::assertStringContainsString('/public_html', $described);
    }
}

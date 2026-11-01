<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Ai\AiCredentialService;
use Fastsite\Ai\DefaultAiClientFactory;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use Fastsite\Translation\EndpointrTranslator;
use Fastsite\Translation\NullTranslator;
use Fastsite\Translation\OpenRouterTranslator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Which AI client a site actually gets. The routing rule that matters most:
 * selecting an AI key on a site must ACTUALLY move translation onto it. It
 * used not to — the owner's endpointr gateway won whenever it was configured,
 * so picking a key changed fact-checking and SEO but left translation on a
 * rate-limited gateway, and the only symptom was a job parking forever.
 */
final class AiRoutingTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private AiCredentialService $credentials;
    private SiteService $sites;
    private DbalSiteRepository $siteRepo;
    private int $userId;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-airoute-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $cipher = new TokenCipher(str_repeat('k', 64));
        $this->credentials = new AiCredentialService($this->databases, $cipher, $clock);
        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $this->sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->sites->create('airoute', 'AI Route', 'https://a.example.com');

        $this->databases->engine()->insert('users', [
            'email' => 'owner@example.com', 'password_hash' => 'x', 'role' => 'admin',
            'created_at' => $clock->iso(), 'updated_at' => $clock->iso(),
        ]);
        $this->userId = (int) $this->databases->engine()->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (is_dir($this->dataPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                \assert($item instanceof \SplFileInfo);
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dataPath);
        }
    }

    private function factory(bool $endpointr = true): DefaultAiClientFactory
    {
        return new DefaultAiClientFactory(
            $this->credentials,
            $endpointr ? 'https://gateway.example.com' : '',
            $endpointr ? 'gw-key' : '',
            'https://openrouter.ai/api',
            '~anthropic/claude-sonnet-latest',
            'perplexity/sonar',
            'perplexity/sonar',
            420,
            'google/gemini-2.5-flash-lite',
        );
    }

    private function site(): \Fastsite\Site\Dto\Site
    {
        $site = $this->siteRepo->findByHandle('airoute');
        \assert($site !== null);

        return $site;
    }

    public function testSelectedKeyWinsOverTheOwnersGatewayForTranslation(): void
    {
        $id = $this->credentials->add($this->userId, 'mine', 'sk-or-abc123456789');
        $this->sites->update('airoute', ['ai_credential_id' => $id]);

        // endpointr IS configured — and must still lose to the explicit pick.
        self::assertInstanceOf(OpenRouterTranslator::class, $this->factory()->translatorForSite($this->site()));
    }

    public function testSiteWithNoKeySelectedStillUsesTheOwnersGateway(): void
    {
        // No credential selected: nothing changes for existing deployments,
        // and no site is silently moved onto user-paid tokens.
        self::assertInstanceOf(EndpointrTranslator::class, $this->factory()->translatorForSite($this->site()));
    }

    public function testNoKeyAndNoGatewayLeavesTranslationUnconfigured(): void
    {
        self::assertInstanceOf(NullTranslator::class, $this->factory(endpointr: false)->translatorForSite($this->site()));
    }

    public function testTranslationUsesItsOwnModelNotTheGeneralOne(): void
    {
        $id = $this->credentials->add(
            $this->userId, 'mine', 'sk-or-abc123456789', '',
            'anthropic/claude-opus-latest', 'perplexity/sonar', 'perplexity/sonar',
            'google/gemini-2.5-flash-lite',
        );
        $this->sites->update('airoute', ['ai_credential_id' => $id]);

        $info = $this->factory()->translatorDescriptionForSite($this->site());
        self::assertSame('OpenRouter', $info['provider']);
        self::assertSame('google/gemini-2.5-flash-lite', $info['model'], 'the cheap translation model, not Opus');
    }

    public function testTranslationFallsBackToTheGeneralModelWhenNoneIsSet(): void
    {
        // An existing key from before the translation model existed keeps
        // behaving exactly as it did — no silent model swap.
        $id = $this->credentials->add($this->userId, 'legacy', 'sk-or-abc123456789', '', 'openai/gpt-4o');
        $this->sites->update('airoute', ['ai_credential_id' => $id]);

        self::assertSame('openai/gpt-4o', $this->factory()->translatorDescriptionForSite($this->site())['model']);
    }

    public function testBothTranslatorsShipInEveryBuildFlavour(): void
    {
        // THE bug that made a selected key look ignored on production. The
        // private deploy trimmer used to `rm OpenRouterTranslator.php`, and the
        // factory guards its OpenRouter branch with class_exists() — so on the
        // deployed image a site's own AI key silently could not translate,
        // while still working for fact-checking and SEO. Only the endpointr
        // client is proprietary; nothing else may be trimmed.
        $scripts = \dirname(__DIR__, 2) . '/scripts';
        // Only what the shell RUNS counts — the comments are free to discuss
        // the file (this one explains why it is no longer deleted).
        $commands = static function (string $path): string {
            $lines = array_filter(
                explode("\n", (string) file_get_contents($path)),
                static fn (string $l): bool => !str_starts_with(ltrim($l), '#'),
            );

            return implode("\n", $lines);
        };
        $private = $commands($scripts . '/deploy-private.sh');
        $public = $commands($scripts . '/deploy-public.sh');

        self::assertStringNotContainsString(
            'rm -f src/Translation/OpenRouterTranslator.php',
            $private,
            'the private build must ship the OpenRouter translator — users pay for their own translation with it',
        );
        self::assertStringNotContainsString(
            'rm -f src/Translation/OpenRouterTranslator.php',
            $public,
            'the public build is OpenRouter-only; removing its translator would leave no translator at all',
        );
        self::assertStringContainsString(
            'rm -f src/Translation/EndpointrTranslator.php',
            $public,
            'the endpointr client is the proprietary file and stays out of the public build',
        );
    }

    public function testSelectedButUnusableKeyIsReportedInsteadOfSilentlyIgnored(): void
    {
        // A selected key that cannot be read must SAY so. Quietly falling back
        // to the gateway is what let "I picked a key and nothing happened" go
        // undiagnosed.
        $id = $this->credentials->add($this->userId, 'mine', 'sk-or-abc123456789');
        $this->sites->update('airoute', ['ai_credential_id' => $id]);
        $this->databases->engine()->executeStatement('DELETE FROM ai_credentials WHERE id = ?', [$id]);

        $info = $this->factory()->translatorDescriptionForSite($this->site());
        self::assertNotSame('', $info['problem']);
        self::assertStringContainsString('could not be read', $info['problem']);
    }

    public function testNoKeySelectedReportsNoProblem(): void
    {
        self::assertSame('', $this->factory()->translatorDescriptionForSite($this->site())['problem']);
    }

    public function testEditingAKeyCanSetTheTranslationModel(): void
    {
        $id = $this->credentials->add($this->userId, 'mine', 'sk-or-abc123456789', '', 'openai/gpt-4o');
        $this->sites->update('airoute', ['ai_credential_id' => $id]);

        $this->credentials->update($id, $this->userId, 'mine', '', 'openai/gpt-4o', 'perplexity/sonar', 'perplexity/sonar', 'anthropic/claude-3.5-haiku');

        self::assertSame('anthropic/claude-3.5-haiku', $this->factory()->translatorDescriptionForSite($this->site())['model']);
    }
}

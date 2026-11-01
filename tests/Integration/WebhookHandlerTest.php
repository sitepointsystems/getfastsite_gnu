<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Webhook\Service\SignatureVerifier;
use Fastsite\Webhook\Service\WebhookService;
use Fastsite\Webhook\WebhookHandler;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Http\Message\ResponseInterface;

/**
 * The inbound webhook endpoint end-to-end at the handler level: both auth
 * schemes (fastsite timestamped HMAC; AutoSEO plain-HMAC/bearer with its
 * X-AutoSEO-* headers), the test-event 200 ack, and the delivery replay guard.
 */
final class WebhookHandlerTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private Client $redis;
    private WebhookHandler $handler;
    private WebhookService $webhooks;
    private SignatureVerifier $verifier;
    private string $secret;

    protected function setUp(): void
    {
        try {
            $this->redis = new Client('tcp://127.0.0.1:6379/15');
            $this->redis->ping();
        } catch (\Throwable) {
            self::markTestSkipped('No Redis on 127.0.0.1:6379 — webhook handler tests skipped.');
        }
        $this->redis->flushdb();

        $this->dataPath = sys_get_temp_dir() . '/fastsite-whh-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        (new Migrator($clock))->migrate($this->databases->engine(), \dirname(__DIR__, 2) . '/migrations/engine');

        $this->webhooks = new WebhookService($this->databases, new TokenCipher('test-secret'), $clock);
        $this->verifier = new SignatureVerifier($clock);
        $queue = new JobQueue($this->databases, $this->redis, $clock);
        $this->handler = new WebhookHandler($this->webhooks, $this->verifier, $queue);

        $created = $this->webhooks->createIntegration('acme-autoseo', 'whtest', 'autoseo');
        // The AutoSEO model: the sender's own endpoint token is the shared
        // secret — stored via setSecret, used as both bearer and HMAC key.
        $this->secret = 'aseo_wh_EXAMPLE0000000000000000';
        $this->webhooks->setSecret($created['id'], 'whtest', $this->secret);
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (!is_dir($this->dataPath)) {
            return;
        }
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

    /**
     * @param array<string, string> $headers
     */
    private function post(string $body, array $headers): ResponseInterface
    {
        $request = new ServerRequest('POST', 'https://engine.example/webhooks/acme-autoseo', $headers, $body);

        return $this->handler->handle($request->withAttribute('integration', 'acme-autoseo'));
    }

    public function testAutoSeoSignedDeliveryIsAccepted(): void
    {
        $body = '{"event":"article.published","id":1,"title":"T"}';
        $response = $this->post($body, [
            'Authorization' => 'Bearer ' . $this->secret,
            'X-AutoSEO-Event' => 'article.published',
            'X-AutoSEO-Signature' => hash_hmac('sha256', $body, $this->secret),
            'X-AutoSEO-Delivery' => 'uuid-1',
        ]);

        // AutoSEO insists on a 200 (it flags anything else as a failed endpoint).
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('accepted', $data['data']['status']);
    }

    public function testAutoSeoBearerAloneIsAccepted(): void
    {
        // The signature header is documented but optional — the bearer token
        // (== the stored secret) authenticates on its own.
        $response = $this->post('{"id":2,"title":"T"}', [
            'Authorization' => 'Bearer ' . $this->secret,
            'X-AutoSEO-Delivery' => 'uuid-2',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testWrongBearerAndSignatureAreRejected(): void
    {
        $body = '{"id":3,"title":"T"}';
        $response = $this->post($body, [
            'Authorization' => 'Bearer wrong-token',
            'X-AutoSEO-Signature' => hash_hmac('sha256', $body, 'wrong-token'),
            'X-AutoSEO-Delivery' => 'uuid-3',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testTestEventGetsPlain200WithoutRecording(): void
    {
        $response = $this->post('{"event":"test"}', [
            'Authorization' => 'Bearer ' . $this->secret,
            'X-AutoSEO-Event' => 'test',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $count = (int) $this->databases->engine()->fetchOne('SELECT COUNT(*) FROM webhook_deliveries');
        self::assertSame(0, $count);
    }

    public function testDuplicateAutoSeoDeliveryAcknowledgedIdempotently(): void
    {
        $body = '{"id":4,"title":"T"}';
        $headers = [
            'Authorization' => 'Bearer ' . $this->secret,
            'X-AutoSEO-Delivery' => 'uuid-4',
        ];

        $first = $this->post($body, $headers);
        $second = $this->post($body, $headers);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        $data = json_decode((string) $second->getBody(), true);
        self::assertSame('duplicate', $data['data']['status']);
    }

    public function testFastsiteSchemeStillWorksAndReturns202(): void
    {
        $body = '{"id":5,"title":"T"}';
        $response = $this->post($body, [
            'X-Fastsite-Signature' => $this->verifier->sign($body, $this->secret),
            'X-Delivery-Id' => 'uuid-5',
        ]);

        self::assertSame(202, $response->getStatusCode());
    }

    public function testGetIsAnUnauthenticatedLivenessAck(): void
    {
        // Sender-side endpoint checkers probe with GET and expect 200 —
        // no auth, and no slug-existence oracle (unknown slug also 200).
        foreach (['acme-autoseo', 'no-such-integration'] as $slug) {
            $request = new ServerRequest('GET', 'https://engine.example/webhooks/' . $slug);
            $response = $this->handler->handle($request->withAttribute('integration', $slug));

            self::assertSame(200, $response->getStatusCode());
            $data = json_decode((string) $response->getBody(), true);
            self::assertSame('ready', $data['data']['status']);
        }
    }

    public function testMissingDeliveryIdIsA400(): void
    {
        $response = $this->post('{"id":6,"title":"T"}', [
            'Authorization' => 'Bearer ' . $this->secret,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }
}

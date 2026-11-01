<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Dashboard\Dash;
use Fastsite\Seo\SeoLengthService;
use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/content/seo-fix — the JSON backend of the SEO-fix
 * progress dialog on the Content screen. The fix runs INLINE (no queue):
 * the dialog drives one request per flagged content row (`op=row&id=N`,
 * a single AI call — seconds, with the outcome and any AI error in the
 * response), then `op=publish` once at the end when anything changed.
 * Client-driven iteration is what makes the progress real: each row is its
 * own short request, so a hung gateway fails ONE step with a visible reason
 * instead of a background job dying silently. While a row's AI answer
 * streams from the gateway, the cumulative byte count lands in a short-TTL
 * Redis key that `op=progress&id=N` reads — the dialog polls it during the
 * row request, so the bar moves WITH the generation instead of stalling
 * until the call returns. MCP/CLI keep the queued seo_fix job for
 * unattended runs.
 */
final class SeoFixDialogHandler implements RequestHandlerInterface
{
    /** Progress keys outlive a poll gap but never a dead run. */
    private const PROGRESS_TTL_SECONDS = 60;

    public function __construct(
        private readonly Dash $dash,
        private readonly SeoLengthService $seo,
        private readonly PublishTrigger $publish,
        private readonly \Predis\Client $redis,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return ApiResponse::error('Site not found.', 404);
        }

        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }

        try {
            switch ((string) ($body['op'] ?? '')) {
                case 'row':
                    $id = (int) ($body['id'] ?? 0);
                    if ($id <= 0) {
                        return ApiResponse::error('Missing content id.', 422);
                    }
                    // One row = one AI call; the rewriter's own curl ceiling
                    // fits, but don't let a default PHP limit kill the
                    // request mid-write.
                    set_time_limit(300);
                    $key = $this->progressKey($site->handle, $id);
                    $lastWrite = 0.0;
                    $result = $this->seo->fix($site, $id, null, function (int $bytes) use ($key, &$lastWrite): void {
                        // Chunks arrive fast — one Redis write per ~150ms is
                        // plenty for a bar polled every few hundred ms.
                        $now = microtime(true);
                        if ($now - $lastWrite < 0.15) {
                            return;
                        }
                        $lastWrite = $now;
                        $this->redis->setex($key, self::PROGRESS_TTL_SECONDS, (string) $bytes);
                    });
                    $this->redis->del($key);

                    return ApiResponse::success($result);

                case 'progress':
                    // Polled by the dialog WHILE its op=row request runs.
                    $bytes = $this->redis->get($this->progressKey($site->handle, (int) ($body['id'] ?? 0)));

                    return ApiResponse::success(['bytes' => $bytes !== null ? (int) $bytes : 0]);

                case 'publish':
                    // Once, after the dialog's last row. Through
                    // PublishTrigger: the rewrites bump content_hash, so a
                    // multilingual site's meta translations just went stale —
                    // translate must run before the build.
                    $this->publish->trigger($site, 'publish: SEO titles/descriptions fixed to snippet limits', 'seo-fix');

                    return ApiResponse::success(['published' => true]);
            }

            return ApiResponse::error('Unknown op.', 422);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    private function progressKey(string $handle, int $contentId): string
    {
        return 'fastsite:seofix:' . $handle . ':' . $contentId;
    }
}

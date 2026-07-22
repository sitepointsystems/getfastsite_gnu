<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Content\Service\ContentService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ContentUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly ContentService $content,
        private readonly AuditLogger $audit,
        private readonly \Fastsite\FactCheck\FactCheckService $factCheck,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);
        $id = (int) $request->getAttribute('id');

        $factCheck = null;
        try {
            $input = ApiHelpers::jsonBody($request);
            if ($site->factCheckEnabled && trim((string) ($input['body_html'] ?? '')) !== '') {
                $res = $this->factCheck->checkAndCorrectBody($site, (string) ($input['title'] ?? ''), (string) $input['body_html']);
                if ($res['changed']) {
                    $input['body_html'] = $res['body'];
                }
                $factCheck = ['changed' => $res['changed'], 'corrected' => $res['corrected'], 'report' => $res['report']];
            }
            $updated = $this->content->update($site, $id, $input, 'api_key:' . $access->apiKeyId);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }

        $this->audit->log($access, 'content.update', $handle, 'content:' . $id, [], ApiHelpers::clientIp($request));

        $data = ApiHelpers::contentToArray($updated);
        if ($factCheck !== null) {
            $data['fact_check'] = $factCheck;
        }

        return ApiResponse::success($data);
    }
}

<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Content\Service\ContentService;
use Fastsite\Content\Service\DuplicateContentException;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ContentCreateHandler implements RequestHandlerInterface
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

        $factCheck = null;
        try {
            $input = ApiHelpers::jsonBody($request);
            $input['source'] = (string) ($input['source'] ?? 'api');
            // Fact-check + auto-correct the body before storing when the site
            // opts in, so the API always publishes corrected content.
            if ($site->factCheckEnabled && trim((string) ($input['body_html'] ?? '')) !== '') {
                $res = $this->factCheck->checkAndCorrectBody($site, (string) ($input['title'] ?? ''), (string) $input['body_html']);
                if ($res['changed']) {
                    $input['body_html'] = $res['body'];
                }
                $factCheck = ['changed' => $res['changed'], 'corrected' => $res['corrected'], 'report' => $res['report']];
            }
            $created = $this->content->create($site, $input, 'api_key:' . $access->apiKeyId);
        } catch (DuplicateContentException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $this->audit->log($access, 'content.create', $handle, 'content:' . $created->id, [
            'type' => $created->type, 'slug' => $created->slug, 'status' => $created->status,
        ], ApiHelpers::clientIp($request));

        $data = ApiHelpers::contentToArray($created);
        if ($factCheck !== null) {
            $data['fact_check'] = $factCheck;
        }

        return ApiResponse::json(['success' => true, 'data' => $data], 201);
    }
}

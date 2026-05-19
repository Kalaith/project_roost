<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthException;
use App\Repositories\ProjectRepository;
use App\Services\AuthService;
use App\Services\SummaryImportService;

final class ImportController
{
    public function __construct(
        private readonly ProjectRepository $projects = new ProjectRepository(),
        private readonly SummaryImportService $imports = new SummaryImportService(),
        private readonly AuthService $auth = new AuthService()
    ) {}

    public function htmlSummary(Request $request, Response $response): void
    {
        try {
            $claims = $this->auth->requireAdminOrPublishToken($request);
        } catch (AuthException $exception) {
            $response->error($exception->getMessage(), $exception->statusCode(), $exception->extra());
            return;
        }

        $body = $request->all();
        $source = (string) ($body['source'] ?? 'apps');
        $hasInlineHtml = isset($body['html']) && is_string($body['html']) && trim($body['html']) !== '';
        $hasInlinePayload = isset($body['payload']) && is_string($body['payload']) && trim($body['payload']) !== '';
        $preview = (bool) ($body['preview'] ?? false);
        $isPublishToken = ($claims['auth_type'] ?? '') === 'publish-token';

        if ($isPublishToken && $preview) {
            $response->error('Publish-token imports cannot run in preview mode.', 403);
            return;
        }

        $html = $hasInlineHtml ? $body['html'] : ($hasInlinePayload ? $body['payload'] : null);
        $batch = $this->imports->parse($source, $html);
        if ($preview) {
            $response->success([
                'source' => $batch['source'],
                'source_hash' => $batch['source_hash'],
                'records' => count($batch['records']),
                'sample' => array_slice($batch['records'], 0, 5),
            ]);
            return;
        }

        $response->success([
            'import' => $this->projects->importBatch($batch),
        ]);
    }
}

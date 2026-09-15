<?php

namespace Dti;

use Dti\Http\ApiException;
use Dti\Http\Request;
use Dti\Http\Response;

/**
 * 요청 하나를 처리하는 조립 지점. 환경($ctx)과 요청($req)을 만들어 라우트 함수에 넘긴다.
 * 테스트는 이 클래스를 직접 만들어 handle() 을 부른다.
 */
final class Kernel
{
    public function __construct(
        private readonly array $config,
        private readonly \PDO $pdo,
        private readonly ?array $identity,
        private readonly ?\Closure $mover = null,
        private readonly ?\Closure $webhook = null,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return $this->route($request);
        } catch (ApiException $e) {
            return Response::json(['detail' => $e->getMessage()], $e->status());
        } catch (\Throwable $e) {
            error_log('[dti] ' . $e);
            return Response::json(['detail' => '요청이 실패했습니다'], 500);
        }
    }

    private function route(Request $request): Response
    {
        $ctx = [
            'config' => $this->config,
            'pdo' => $this->pdo,
            'identity' => $this->identity,
            'mover' => $this->mover,
            'webhook' => $this->webhook,
        ];
        $req = [
            'method' => $request->method,
            'seg' => $request->segments,
            'body' => $request->body,
            'query' => $request->query,
            'files' => $request->files,
        ];

        // whoami 만은 미로그인에서도 답한다 — 화면이 여기서 받은 portal_url 로 되돌아간다
        if (($req['seg'][0] ?? '') === 'whoami') return dti_route_identity($ctx, $req);
        if (!$this->identity) throw new ApiException('로그인이 필요합니다', 401);

        return match ($req['seg'][0] ?? '') {
            'members' => dti_route_identity($ctx, $req),
            'topics' => dti_route_topics($ctx, $req),
            'fields' => dti_route_fields($ctx, $req),
            'score' => dti_route_scores($ctx, $req),
            default => throw new ApiException('없는 API 입니다', 404),
        };
    }
}

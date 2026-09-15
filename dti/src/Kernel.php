<?php

namespace Dti;

use Dti\Controller\IdentityController;
use Dti\Http\ApiException;
use Dti\Http\Request;
use Dti\Http\Response;
use Dti\Identity\Identity;
use Dti\Identity\Members;

/**
 * 요청 하나를 처리하는 조립 지점. 컨트롤러·리포지터리·서비스를 여기서 만든다.
 * 테스트는 이 클래스를 직접 만들어 handle() 을 부른다(파이썬의 create_app 자리).
 */
final class Kernel
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly ?Identity $identity,
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
        // whoami 만은 미로그인에서도 답한다 — 화면이 여기서 받은 portal_url 로 되돌아간다
        if ($request->segment(0) === 'whoami') {
            return $this->identityController()->whoami();
        }
        if (!$this->identity) throw new ApiException('로그인이 필요합니다', 401);

        if ($request->segment(0) === 'members') {
            return $this->identityController()->members();
        }

        throw new ApiException('없는 API 입니다', 404);
    }

    private function identityController(): IdentityController
    {
        return new IdentityController($this->config, $this->members(), $this->identity);
    }

    private function members(): Members
    {
        return new Members($this->db->pdo(), $this->config);
    }
}

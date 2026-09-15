<?php

namespace Dti;

use Dti\Controller\IdentityController;
use Dti\Controller\TopicController;
use Dti\Http\ApiException;
use Dti\Http\Request;
use Dti\Http\Response;
use Dti\Identity\Identity;
use Dti\Identity\Members;
use Dti\Repository\EmotionRepository;
use Dti\Repository\PresentationRepository;
use Dti\Repository\TopicRepository;
use Dti\Service\PresentationService;
use Dti\Service\Storage;

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

        return match ($request->segment(0)) {
            'members' => $this->identityController()->members(),
            'topics' => $this->routeTopics($request),
            default => throw new ApiException('없는 API 입니다', 404),
        };
    }

    private function routeTopics(Request $request): Response
    {
        $topics = $this->topicController();
        $tid = $request->segment(1);

        if ($tid === null) {
            return match ($request->method) {
                'GET' => $topics->index(),
                'POST' => $topics->create($request->body),
                default => throw new ApiException('없는 API 입니다', 404),
            };
        }

        return match ([$request->method, $request->segment(2)]) {
            ['GET', null] => $topics->show((int)$tid),
            ['PUT', null] => $topics->update((int)$tid, $request->body),
            ['DELETE', null] => $topics->destroy((int)$tid),
            ['POST', 'claim'] => $topics->claim((int)$tid, $request->body),
            ['POST', 'release'] => $topics->release((int)$tid),
            ['POST', 'schedule'] => $topics->schedule((int)$tid, $request->body),
            ['POST', 'complete'] => $topics->complete((int)$tid, $request->body),
            ['POST', 'assign'] => $topics->assign((int)$tid, $request->body),
            default => throw new ApiException('없는 API 입니다', 404),
        };
    }

    private function identityController(): IdentityController
    {
        return new IdentityController($this->config, $this->members(), $this->identity);
    }

    private function topicController(): TopicController
    {
        $pdo = $this->db->pdo();
        return new TopicController(
            $this->config,
            new TopicRepository($pdo),
            new PresentationRepository($pdo),
            new EmotionRepository($pdo),
            $this->presentationService(),
            $this->members(),
            $this->identity,
        );
    }

    private function presentationService(): PresentationService
    {
        $pdo = $this->db->pdo();
        return new PresentationService(
            new PresentationRepository($pdo),
            new EmotionRepository($pdo),
            new Storage($this->config),
        );
    }

    private function members(): Members
    {
        return new Members($this->db->pdo(), $this->config);
    }
}

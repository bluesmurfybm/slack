<?php

namespace Dti;

use Dti\Controller\IdentityController;
use Dti\Controller\EmotionController;
use Dti\Controller\MaterialController;
use Dti\Controller\RelatedController;
use Dti\Controller\ScoreController;
use Dti\Controller\FieldController;
use Dti\Controller\TopicController;
use Dti\Http\ApiException;
use Dti\Http\Request;
use Dti\Http\Response;

/**
 * 요청 하나를 처리하는 조립 지점. 컨트롤러·리포지터리·서비스를 여기서 만든다.
 * 테스트는 이 클래스를 직접 만들어 handle() 을 부른다(파이썬의 create_app 자리).
 */
final class Kernel
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
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
        // whoami 만은 미로그인에서도 답한다 — 화면이 여기서 받은 portal_url 로 되돌아간다
        if ($request->segment(0) === 'whoami') {
            return $this->identityController()->whoami();
        }
        if (!$this->identity) throw new ApiException('로그인이 필요합니다', 401);

        return match ($request->segment(0)) {
            'members' => $this->identityController()->members(),
            'topics' => $this->routeTopics($request),
            'fields' => $this->routeFields($request),
            'score' => $this->routeScore($request),
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
            ['GET', 'related'] => (new RelatedController($this->db->pdo()))->index((int)$tid),
            ['POST', 'emotions'] => $this->emotionController()->toggle((int)$tid, (string)$request->segment(3)),
            default => $this->routeMaterial($request, (int)$tid),
        };
    }

    private function routeMaterial(Request $request, int $tid): Response
    {
        $slot = (string)$request->segment(2);
        $material = $this->materialController();

        return match ([$request->method, $request->segment(3)]) {
            ['POST', 'link'] => $material->attachLink($tid, $slot, $request->body),
            ['POST', 'file'] => $material->attachFile($tid, $slot, $request->files),
            ['GET', 'download'] => $material->download($tid, $slot),
            ['DELETE', null] => $material->detach($tid, $slot),
            default => throw new ApiException('없는 API 입니다', 404),
        };
    }

    private function identityController(): IdentityController
    {
        return new IdentityController($this->config, $this->db->pdo(), $this->identity);
    }

    private function routeScore(Request $request): Response
    {
        if ($request->method !== 'GET' || $request->segment(1) !== null) {
            throw new ApiException('없는 API 입니다', 404);
        }

        return (new ScoreController($this->config, $this->db->pdo(), $this->identity))->index($request->query);
    }

    private function routeFields(Request $request): Response
    {
        $fields = new FieldController($this->config, $this->db->pdo(), $this->identity);
        $fid = $request->segment(1);

        return match ([$request->method, $fid === null]) {
            ['GET', true] => $fields->index(),
            ['POST', true] => $fields->create($request->body),
            ['DELETE', false] => $fields->destroy((int)$fid),
            default => throw new ApiException('없는 API 입니다', 404),
        };
    }

    private function topicController(): TopicController
    {
        $pdo = $this->db->pdo();
        return new TopicController(
            $this->config,
            $pdo,
            $this->webhook,
            $this->identity,
        );
    }

    private function emotionController(): EmotionController
    {
        return new EmotionController($this->db->pdo(), $this->identity);
    }

    private function materialController(): MaterialController
    {
        return new MaterialController($this->config, $this->db->pdo(), $this->mover, $this->identity);
    }
}

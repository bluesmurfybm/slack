<?php

namespace Dti\Controller;

use Dti\Config;
use Dti\Entity\Presentation;
use Dti\Entity\Topic;
use Dti\Http\ApiException;
use Dti\Http\Input;
use Dti\Http\Response;
use Dti\Identity\Identity;
use Dti\Repository\PresentationRepository;
use Dti\Repository\TopicRepository;
use Dti\Service\PresentationService;
use Dti\Service\Slot;
use Dti\Service\Storage;
use Dti\Service\TopicPresenter;

final class MaterialController
{
    public function __construct(
        private readonly Config $config,
        private readonly TopicRepository $topics,
        private readonly PresentationRepository $presentations,
        private readonly PresentationService $service,
        private readonly Storage $storage,
        private readonly Identity $identity,
    ) {}

    public function attachLink(int $tid, string $slotName, array $body): Response
    {
        $slot = new Slot($slotName);
        [$topic, $pres] = $this->guard($tid);

        $url = Input::url($body['url'] ?? '', '주소');
        if ($url === '') throw new ApiException('http(s) 로 시작하는 주소만 넣을 수 있습니다', 422);

        $holder = $this->holderForWrite($slot, $topic, $pres);
        $this->storage->remove($slot->get($holder, 'path'));
        $slot->set($holder, [
            'kind' => 'link',
            'url' => $url,
            'name' => Input::str($body, 'name', '자료 이름') ?: $url,
            'path' => null,
        ]);

        return $this->save($topic, $holder);
    }

    public function attachFile(int $tid, string $slotName, array $files): Response
    {
        $slot = new Slot($slotName);
        [$topic, $pres] = $this->guard($tid);

        $file = $files['file'] ?? null;
        if (!is_array($file)) throw new ApiException('파일을 올려 주세요', 422);

        $stored = $this->storage->save($tid, $file);

        $holder = $this->holderForWrite($slot, $topic, $pres);
        $this->storage->remove($slot->get($holder, 'path'));
        $slot->set($holder, [
            'kind' => 'file',
            'path' => $stored,
            'url' => null,
            'name' => basename((string)($file['name'] ?? '자료')),
        ]);

        return $this->save($topic, $holder);
    }

    public function detach(int $tid, string $slotName): Response
    {
        $slot = new Slot($slotName);
        [$topic, $pres] = $this->guard($tid);

        $holder = $slot->holder($topic, $pres);
        if ($holder === null) {
            return Response::json(TopicPresenter::present($topic, null));
        }

        $this->storage->remove($slot->get($holder, 'path'));
        $slot->set($holder, ['kind' => null, 'name' => null, 'url' => null, 'path' => null]);

        return $this->save($topic, $holder);
    }

    public function download(int $tid, string $slotName): Response
    {
        $slot = new Slot($slotName);
        $topic = $this->topics->findOrFail($tid);
        $holder = $slot->holder($topic, $this->presentations->ofTopic($tid));

        $path = $this->storage->resolve($slot->get($holder, 'path'));
        $name = $slot->get($holder, 'name') ?? basename($path);

        return Response::file($path, $name);
    }

    /** @return array{0: Topic, 1: ?Presentation} */
    private function guard(int $tid): array
    {
        $topic = $this->topics->findOrFail($tid);
        $pres = $this->presentations->ofTopic($tid);
        if (!$this->service->mayManage($pres, $this->identity, $this->config)) {
            throw new ApiException('발표자 본인이나 관리자만 자료를 올릴 수 있습니다', 403);
        }
        return [$topic, $pres];
    }

    private function holderForWrite(Slot $slot, Topic $topic, ?Presentation $pres): Topic|Presentation
    {
        return $slot->holder($topic, $pres) ?? $this->service->create((int)$topic->id);
    }

    private function save(Topic $topic, Topic|Presentation $holder): Response
    {
        if ($holder instanceof Topic) {
            $this->topics->update($holder);
        } else {
            $this->presentations->update($holder);
        }

        return Response::json(
            TopicPresenter::present($topic, $this->presentations->ofTopic((int)$topic->id)));
    }
}

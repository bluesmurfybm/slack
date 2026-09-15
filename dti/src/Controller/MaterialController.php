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
use Dti\Service\TopicPresenter;

final class MaterialController
{
    public function __construct(
        private readonly Config $config,
        private readonly TopicRepository $topics,
        private readonly PresentationRepository $presentations,
        private readonly PresentationService $service,
        private readonly ?\Closure $mover,
        private readonly Identity $identity,
    ) {}

    public function attachLink(int $tid, string $slotName, array $body): Response
    {
        $slot = dti_slot_check($slotName);
        [$topic, $pres] = $this->guard($tid);

        $url = Input::url($body['url'] ?? '', '주소');
        if ($url === '') throw new ApiException('http(s) 로 시작하는 주소만 넣을 수 있습니다', 422);

        $holder = $this->holderForWrite($slot, $topic, $pres);
        $this->removeFile($holder, $slot);
        $this->slotSet($holder, $slot, [
            'kind' => 'link',
            'url' => $url,
            'name' => Input::str($body, 'name', '자료 이름') ?: $url,
            'path' => null,
        ]);

        return $this->save($topic, $holder);
    }

    public function attachFile(int $tid, string $slotName, array $files): Response
    {
        $slot = dti_slot_check($slotName);
        [$topic, $pres] = $this->guard($tid);

        $file = $files['file'] ?? null;
        if (!is_array($file)) {
            // post_max_size 를 넘기면 PHP 가 $_FILES 를 통째로 비워 보낸다. 그것도 용량 초과다
            throw new ApiException($this->config->maxUploadMb . 'MB 까지 올릴 수 있습니다', 413);
        }

        $stored = dti_save_upload($this->config->uploadDir, $this->config->maxUploadMb,
                                 $tid, $file, $this->mover);

        $holder = $this->holderForWrite($slot, $topic, $pres);
        $this->removeFile($holder, $slot);
        $this->slotSet($holder, $slot, [
            'kind' => 'file',
            'path' => $stored,
            'url' => null,
            'name' => basename((string)($file['name'] ?? '자료')),
        ]);

        return $this->save($topic, $holder);
    }

    public function detach(int $tid, string $slotName): Response
    {
        $slot = dti_slot_check($slotName);
        [$topic, $pres] = $this->guard($tid);

        $holder = $this->slotHolder($slot, $topic, $pres);
        if ($holder === null) {
            return Response::json(TopicPresenter::present($topic, null));
        }

        $this->removeFile($holder, $slot);
        $this->slotSet($holder, $slot, ['kind' => null, 'name' => null, 'url' => null, 'path' => null]);

        return $this->save($topic, $holder);
    }

    public function download(int $tid, string $slotName): Response
    {
        $slot = dti_slot_check($slotName);
        $topic = $this->topics->findOrFail($tid);
        $holder = $this->slotHolder($slot, $topic, $this->presentations->ofTopic($tid));

        $path = dti_resolve_upload($this->config->uploadDir, $this->slotGet($holder, $slot, 'path'));
        $name = $this->slotGet($holder, $slot, 'name') ?? basename($path);

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

    private function holderForWrite(string $slot, Topic $topic, ?Presentation $pres): Topic|Presentation
    {
        return $this->slotHolder($slot, $topic, $pres) ?? $this->service->create((int)$topic->id);
    }

    private function slotHolder(string $slot, Topic $topic, ?Presentation $pres): Topic|Presentation|null
    {
        return dti_slot_on_topic($slot) ? $topic : $pres;
    }

    private function slotGet(Topic|Presentation|null $holder, string $slot, string $field): ?string
    {
        return $holder === null ? null : $holder->{dti_slot_column($slot, $field)};
    }

    /** @param array<string, ?string> $values kind·name·url·path 중 채울 것 */
    private function slotSet(Topic|Presentation $holder, string $slot, array $values): void
    {
        foreach ($values as $field => $value) {
            $holder->{dti_slot_column($slot, $field)} = $value;
        }
    }

    private function removeFile(Topic|Presentation $holder, string $slot): void
    {
        dti_remove_upload($this->config->uploadDir, $this->slotGet($holder, $slot, 'path'));
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

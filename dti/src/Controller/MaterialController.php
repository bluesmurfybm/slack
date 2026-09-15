<?php

namespace Dti\Controller;

use Dti\Http\ApiException;
use Dti\Http\Input;
use Dti\Http\Response;

final class MaterialController
{
    public function __construct(
        private readonly array $config,
        private readonly \PDO $pdo,
        private readonly ?\Closure $mover,
        private readonly array $identity,
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

        return $this->save($topic, $holder, $slot);
    }

    public function attachFile(int $tid, string $slotName, array $files): Response
    {
        $slot = dti_slot_check($slotName);
        [$topic, $pres] = $this->guard($tid);

        $file = $files['file'] ?? null;
        if (!is_array($file)) {
            // post_max_size 를 넘기면 PHP 가 $_FILES 를 통째로 비워 보낸다. 그것도 용량 초과다
            throw new ApiException($this->config['max_upload_mb'] . 'MB 까지 올릴 수 있습니다', 413);
        }

        $stored = dti_save_upload($this->config['upload_dir'], $this->config['max_upload_mb'],
                                 $tid, $file, $this->mover);

        $holder = $this->holderForWrite($slot, $topic, $pres);
        $this->removeFile($holder, $slot);
        $this->slotSet($holder, $slot, [
            'kind' => 'file',
            'path' => $stored,
            'url' => null,
            'name' => basename((string)($file['name'] ?? '자료')),
        ]);

        return $this->save($topic, $holder, $slot);
    }

    public function detach(int $tid, string $slotName): Response
    {
        $slot = dti_slot_check($slotName);
        [$topic, $pres] = $this->guard($tid);

        $holder = $this->slotHolder($slot, $topic, $pres);
        if ($holder === null) {
            return Response::json(dti_topic_present($topic, null));
        }

        $this->removeFile($holder, $slot);
        $this->slotSet($holder, $slot, ['kind' => null, 'name' => null, 'url' => null, 'path' => null]);

        return $this->save($topic, $holder, $slot);
    }

    public function download(int $tid, string $slotName): Response
    {
        $slot = dti_slot_check($slotName);
        $topic = dti_topic_find_or_fail($this->pdo, $tid);
        $holder = $this->slotHolder($slot, $topic, dti_presentation_of_topic($this->pdo, $tid));

        $path = dti_resolve_upload($this->config['upload_dir'], $this->slotGet($holder, $slot, 'path'));
        $name = $this->slotGet($holder, $slot, 'name') ?? basename($path);

        return Response::file($path, $name);
    }

    private function guard(int $tid): array
    {
        $topic = dti_topic_find_or_fail($this->pdo, $tid);
        $pres = dti_presentation_of_topic($this->pdo, $tid);
        if (!dti_may_manage($pres, $this->identity['email'], dti_is_admin($this->config, $this->identity['email']))) {
            throw new ApiException('발표자 본인이나 관리자만 자료를 올릴 수 있습니다', 403);
        }
        return [$topic, $pres];
    }

    private function holderForWrite(string $slot, array $topic, ?array $pres): array
    {
        return $this->slotHolder($slot, $topic, $pres)
            ?? dti_presentation_create($this->pdo, (int)$topic['id']);
    }

    private function slotHolder(string $slot, array $topic, ?array $pres): ?array
    {
        return dti_slot_on_topic($slot) ? $topic : $pres;
    }

    private function slotGet(?array $holder, string $slot, string $field): ?string
    {
        return $holder === null ? null : $holder[dti_slot_column($slot, $field)];
    }

    /** @param array<string, ?string> $values kind·name·url·path 중 채울 것 */
    private function slotSet(array &$holder, string $slot, array $values): void
    {
        foreach ($values as $field => $value) {
            $holder[dti_slot_column($slot, $field)] = $value;
        }
    }

    private function removeFile(array $holder, string $slot): void
    {
        dti_remove_upload($this->config['upload_dir'], $this->slotGet($holder, $slot, 'path'));
    }

    private function save(array $topic, array $holder, string $slot): Response
    {
        if (dti_slot_on_topic($slot)) {
            dti_topic_update($this->pdo, $holder);
            // 스캔 칸은 아티클 행에 있다. 배열은 복사되므로 고친 쪽을 화면에 내보낸다
            $topic = $holder;
        } else {
            dti_presentation_update($this->pdo, $holder);
        }

        return Response::json(
            dti_topic_present($topic, dti_presentation_of_topic($this->pdo, (int)$topic['id'])));
    }
}

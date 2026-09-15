<?php

namespace Dti\Controller;

use Dti\Http\ApiException;
use Dti\Http\Input;
use Dti\Http\Response;

final class TopicController
{
    public function __construct(
        private readonly array $config,
        private readonly \PDO $pdo,
        private readonly ?\Closure $webhook,
        private readonly array $identity,
    ) {}

    public function index(): Response
    {
        // 숨김·보관은 관리자 화면에만 있어야 한다. 목록에서 빼는 판정은 서버가 한다
        $rows = dti_topic_list_with_presentations($this->pdo, dti_is_admin($this->config, $this->identity['email']));
        [$counts, $mine] = dti_emotion_summary($this->pdo, $this->identity['email']);

        $out = [];
        foreach ($rows as [$topic, $pres]) {
            $out[] = dti_topic_present($topic, $pres, $counts[$topic['id']] ?? null, $mine[$topic['id']] ?? null);
        }
        return Response::json($out);
    }

    public function show(int $tid): Response
    {
        $topic = dti_topic_find_or_fail($this->pdo, $tid);
        return Response::json(dti_topic_present($topic, dti_presentation_of_topic($this->pdo, $tid)));
    }

    public function create(array $body): Response
    {
        $this->requireAdmin();

        $topic = dti_topic_new();
        $this->fill($topic, $body, defaults: true);
        $topic['created_by'] = $this->identity['email'];
        $topic['created_at'] = date('Y-m-d H:i:s');
        $tid = dti_topic_insert($this->pdo, $topic);

        $plannedDate = Input::date($body['planned_date'] ?? '', '예정일');
        if ($plannedDate !== '') {
            dti_presentation_create($this->pdo, $tid, ['planned_date' => $plannedDate]);
        }
        dti_related_rebuild($this->pdo);

        return Response::json(
            dti_topic_present($topic, dti_presentation_of_topic($this->pdo, $tid)), 201);
    }

    public function update(int $tid, array $body): Response
    {
        $this->requireAdmin();

        $topic = dti_topic_find_or_fail($this->pdo, $tid);
        $this->fill($topic, $body, defaults: false);
        dti_topic_update($this->pdo, $topic);

        // 예정일은 아티클이 아니라 발표 행에 있다. 값이 왔을 때만 손댄다
        if (array_key_exists('planned_date', $body)) {
            $plannedDate = Input::date($body['planned_date'], '예정일');
            $pres = dti_presentation_of_topic($this->pdo, $tid);
            if ($pres === null && $plannedDate !== '') {
                $pres = dti_presentation_create($this->pdo, $tid, ['planned_date' => $plannedDate]);
            } elseif ($pres !== null) {
                $pres['planned_date'] = $plannedDate;
                dti_presentation_update($this->pdo, $pres);
            }
        }

        dti_related_rebuild($this->pdo);

        return Response::json(dti_topic_present($topic, dti_presentation_of_topic($this->pdo, $tid)));
    }

    public function destroy(int $tid): Response
    {
        $this->requireAdmin();

        $topic = dti_topic_find_or_fail($this->pdo, $tid);
        $pres = dti_presentation_of_topic($this->pdo, $tid);
        if ($pres) dti_presentation_purge($this->pdo, $this->config['upload_dir'], $pres);
        dti_topic_delete($this->pdo, (int)$topic['id']);
        dti_related_rebuild($this->pdo);

        return Response::json(['ok' => true]);
    }

    public function claim(int $tid, array $body): Response
    {
        $topic = dti_topic_find_or_fail($this->pdo, $tid);
        if ($topic['active'] !== 1 || $topic['archived'] !== 0) {
            throw new ApiException('지금은 예약할 수 없는 아티클입니다', 409);
        }

        $plannedDate = array_key_exists('planned_date', $body)
            ? Input::date($body['planned_date'], '예정일') : null;
        if ($plannedDate === '') $plannedDate = null;

        // 동시 예약 방지 — 조건부 UPDATE 한 방. 발표자 없는 행(자료만 등)이 있으면 그 행을 차지한다
        $taken = dti_presentation_claim(
            $this->pdo, $tid, $this->identity['email'], $this->identity['name'], $plannedDate);

        if (!$taken) {
            try {
                $values = ['presenter_email' => $this->identity['email'], 'presenter' => $this->identity['name']];
                if ($plannedDate !== null) $values['planned_date'] = $plannedDate;
                dti_presentation_create($this->pdo, $tid, $values);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                throw new ApiException('이미 예약되었거나 발표가 끝난 아티클입니다', 409);
            }
        }

        $pres = dti_presentation_of_topic($this->pdo, $tid);
        dti_notify_new_presenter($this->config['slack_webhook'], $this->webhook,
            $topic['title'], $pres['presenter'], $pres['planned_date']);

        return Response::json(dti_topic_present($topic, $pres));
    }

    public function release(int $tid): Response
    {
        $topic = dti_topic_find_or_fail($this->pdo, $tid);
        $pres = dti_presentation_of_topic($this->pdo, $tid);
        if (!dti_may_manage($pres, $this->identity['email'], dti_is_admin($this->config, $this->identity['email']))) {
            throw new ApiException('본인이 예약한 아티클만 취소할 수 있습니다', 403);
        }
        if ($pres) dti_presentation_unassign($this->pdo, $this->config['upload_dir'], $pres);

        return Response::json(dti_topic_present($topic, dti_presentation_of_topic($this->pdo, $tid)));
    }

    public function schedule(int $tid, array $body): Response
    {
        // claim 은 아무도 안 잡은 주제에만 걸려서, 예약 후 날짜를 넣을 경로가 따로 필요하다
        $topic = dti_topic_find_or_fail($this->pdo, $tid);
        $pres = dti_presentation_of_topic($this->pdo, $tid);
        if (!dti_may_manage($pres, $this->identity['email'], dti_is_admin($this->config, $this->identity['email']))) {
            throw new ApiException('본인이 예약한 아티클만 예정일을 정할 수 있습니다', 403);
        }
        if ($pres === null) throw new ApiException('예약이 없는 아티클입니다', 409);
        if ($pres['done_date'] !== '') throw new ApiException('이미 발표가 끝난 아티클입니다', 409);

        $pres['planned_date'] = Input::date($body['planned_date'] ?? '', '예정일');
        dti_presentation_update($this->pdo, $pres);

        return Response::json(dti_topic_present($topic, $pres));
    }

    public function complete(int $tid, array $body): Response
    {
        $this->requireAdmin();

        $topic = dti_topic_find_or_fail($this->pdo, $tid);
        $pres = dti_presentation_of_topic($this->pdo, $tid) ?? dti_presentation_create($this->pdo, $tid);
        $pres['done_date'] = Input::date($body['done_date'] ?? '', '발표일') ?: date('Y-m-d');
        dti_presentation_update($this->pdo, $pres);

        return Response::json(dti_topic_present($topic, $pres));
    }

    public function assign(int $tid, array $body): Response
    {
        // 예약과 달리 이미 예약된 주제도 덮어쓴다 — 배정 권한은 관리자에게 있다
        $this->requireAdmin();

        $topic = dti_topic_find_or_fail($this->pdo, $tid);
        $email = Input::str($body, 'email', '발표자');
        $members = dti_members_all($this->pdo, $this->config);
        if ($email !== '' && !dti_members_has($members, $email)) {
            throw new ApiException('명단에 없는 사람입니다', 422);
        }

        $pres = dti_presentation_of_topic($this->pdo, $tid);

        if ($email === '') {
            if ($pres) dti_presentation_unassign($this->pdo, $this->config['upload_dir'], $pres);
            return Response::json(dti_topic_present($topic, dti_presentation_of_topic($this->pdo, $tid)));
        }

        $values = ['presenter_email' => $email, 'presenter' => dti_members_name_of($members, $email)];
        if (array_key_exists('planned_date', $body)) {
            $values['planned_date'] = Input::date($body['planned_date'], '예정일');
        }

        if ($pres) {
            foreach ($values as $field => $value) {
                $pres[$field] = $value;
            }
            dti_presentation_update($this->pdo, $pres);
        } else {
            $pres = dti_presentation_create($this->pdo, $tid, $values);
        }
        dti_notify_new_presenter($this->config['slack_webhook'], $this->webhook,
            $topic['title'], $pres['presenter'], $pres['planned_date']);

        return Response::json(dti_topic_present($topic, $pres));
    }

    /**
     * 본문의 값을 아티클에 채운다. 등록은 빠진 값을 기본값으로 두고(defaults),
     * 수정은 본문에 온 키만 바꾼다 — 파이썬 TopicPatch 의 exclude_unset 과 같아야 한다.
     */
    private function fill(array &$topic, array $body, bool $defaults): void
    {
        $has = static fn (string $key) => array_key_exists($key, $body);

        if ($defaults || $has('title')) $topic['title'] = Input::str($body, 'title', '제목', required: true);
        if ($defaults || $has('field')) $topic['field'] = Input::str($body, 'field', '분야');
        if ($defaults || $has('keywords')) $topic['keywords'] = Input::str($body, 'keywords', '키워드');
        if ($defaults || $has('volume')) $topic['volume'] = Input::str($body, 'volume', 'Volume');
        if ($defaults || $has('page')) $topic['page'] = Input::str($body, 'page', 'Page');
        if ($defaults || $has('note')) $topic['note'] = Input::str($body, 'note', '비고');
        if ($defaults || $has('requirement')) {
            $topic['requirement'] = Input::str($body, 'requirement', '발표구분') ?: 'recommended';
        }
        if ($defaults || $has('magazine')) {
            $topic['magazine'] = Input::oneOf($body['magazine'] ?? '', DTI_MAGAZINES, '매거진');
        }
        if ($defaults || $has('team')) {
            $topic['team'] = Input::oneOf($body['team'] ?? '', DTI_TEAMS, '팀');
        }
        if ($defaults || $has('year')) $topic['year'] = Input::nullableInt($body, 'year', '년도');
        if ($has('active')) $topic['active'] = Input::flag($body['active']);
        if ($has('archived')) $topic['archived'] = Input::flag($body['archived']);
    }

    private function requireAdmin(): void
    {
        if (!dti_is_admin($this->config, $this->identity['email'])) {
            throw new ApiException('관리자만 할 수 있습니다', 403);
        }
    }
}

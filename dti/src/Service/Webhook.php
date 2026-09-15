<?php

namespace Dti\Service;

/** 슬랙 웹훅 전송. 테스트는 이 자리에 기록만 하는 구현을 넣는다. */
interface Webhook
{
    public function post(string $url, array $payload): void;
}

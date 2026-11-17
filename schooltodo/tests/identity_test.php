<?php

namespace Schooltodo\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class identity_test extends TestCase
{
    /** schooltodo_bot_identity: bot_key 와 같은 키면 bot=true identity 조회 */
    public function test_맞는_키(): void
    {
        $identity = schooltodo_bot_identity(schooltodo_config(['bot_key' => 'bot-secret']), 'bot-secret');

        $this->assertSame(['email' => '', 'name' => '봇', 'color' => '', 'bot' => true], $identity);
    }

    /** schooltodo_bot_identity: bot_key 와 다른 키면 null */
    public function test_틀린_키(): void
    {
        $identity = schooltodo_bot_identity(schooltodo_config(['bot_key' => 'bot-secret']), 'wrong');

        $this->assertNull($identity);
    }

    /** schooltodo_bot_identity: bot_key 가 빈 값이면 어떤 키도 null */
    public function test_bot_key_미설정(): void
    {
        $identity = schooltodo_bot_identity(schooltodo_config(['bot_key' => '']), '');

        $this->assertNull($identity);
    }

    /** schooltodo_identity: 틀린 API 키는 세션으로 넘어가지 않고 null */
    public function test_틀린_키는_세션을_보지_않음(): void
    {
        $identity = schooltodo_identity(schooltodo_config(['bot_key' => 'bot-secret']), 'wrong');

        $this->assertNull($identity);
    }
}

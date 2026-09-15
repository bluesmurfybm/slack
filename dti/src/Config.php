<?php

namespace Dti;

/**
 * dti 설정의 원본. 파이썬 magazine 의 core/config.py(Settings + MEMBERS) 자리다.
 * 값을 읽는 곳은 여기 하나뿐이고, 테스트는 이 객체만 갈아끼운다.
 */
final class Config
{
    public const TEAMS = ['APP', 'SQUARE', 'LAB'];
    public const MAGAZINES = ['DI', 'MIT TR', 'Etc'];
    public const REQUIREMENTS = ['required', 'recommended', 'normal'];
    public const EMOTIONS = ['like', 'apply', 'easy', 'new'];
    public const DEFAULT_FIELDS = ['UI/UX', 'Marketing', 'Trend', 'AX', 'Etc'];

    public const DEFAULT_ADMINS = ['jian@bluesoft.co.kr', 'kimhy@bluesoft.co.kr'];

    // 분야는 DB 에서 동적으로 관리하지만 팀은 코드에 둔다. 입·퇴사 때 여기를 고친다.
    public const DEFAULT_TEAMS = [
        'kimhy@bluesoft.co.kr' => ['APP', 'SQUARE', 'LAB'],
        'jian@bluesoft.co.kr' => ['SQUARE'],
        'scpark@bluesoft.co.kr' => ['APP'],
        'pink@bluesoft.co.kr' => ['LAB'],
        'venus@bluesoft.co.kr' => ['APP'],
        'akddd@bluesoft.co.kr' => ['APP'],
        'lenda83@bluesoft.co.kr' => ['APP', 'LAB'],
        'amitoa@bluesoft.co.kr' => ['APP'],
        'phr@bluesoft.co.kr' => ['APP'],
        'bnmmnbhj@bluesoft.co.kr' => ['APP'],
        'siyu@bluesoft.co.kr' => ['APP'],
        'hjlee@bluesoft.co.kr' => ['APP'],
        'jun0@bluesoft.co.kr' => ['APP'],
    ];

    /** @var string[] 소문자로 정규화된 관리자 이메일 */
    public readonly array $adminEmails;
    /** @var array<string, string[]> */
    public readonly array $teamsByEmail;

    public function __construct(
        public readonly array $db,
        public readonly string $uploadDir,
        public readonly int $maxUploadMb = 50,
        ?array $adminEmails = null,
        ?array $teamsByEmail = null,
        public readonly ?string $slackWebhook = null,
        public readonly string $portalUrl = '../index.php',
        public readonly string $slackUrl = '../slack/lists.php',
        public readonly string $seedPath = '',
    ) {
        $this->adminEmails = array_map('strtolower', $adminEmails ?? self::DEFAULT_ADMINS);
        $this->teamsByEmail = $teamsByEmail ?? self::DEFAULT_TEAMS;

        foreach ($this->teamsByEmail as $email => $teams) {
            $unknown = array_diff($teams, self::TEAMS);
            if ($unknown) {
                throw new \InvalidArgumentException('없는 팀입니다: ' . implode(', ', $unknown) . " ({$email})");
            }
        }
    }

    public static function fromPortal(?string $dbName = null): self
    {
        $cfg = require __DIR__ . '/../../config.php';
        $db = $cfg['db'];
        if ($dbName !== null) $db['name'] = $dbName;

        return new self(
            db: $db,
            uploadDir: __DIR__ . '/../var/uploads',
            slackWebhook: $cfg['dti_slack_webhook'] ?? null,
            seedPath: __DIR__ . '/../../magazine/data/seed.json',
        );
    }

    public function maxUploadBytes(): int
    {
        return $this->maxUploadMb * 1024 * 1024;
    }

    public function isAdmin(?string $email): bool
    {
        return $email !== null && in_array(strtolower($email), $this->adminEmails, true);
    }

    public function teamsOf(?string $email): array
    {
        return $this->teamsByEmail[strtolower((string)$email)] ?? [];
    }
}

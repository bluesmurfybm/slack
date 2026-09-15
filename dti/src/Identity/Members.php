<?php

namespace Dti\Identity;

use Dti\Config;
use PDO;

/**
 * 구성원 명단. 포털 계정이 곧 명단이다 — 파이썬은 core/config.py 에 하드코딩했지만
 * 그건 magazine 이 포털 MySQL 을 보지 못해서였다. 입·퇴사 때 고칠 곳이 한 군데로 준다.
 */
final class Members
{
    /** @var array<int, array{name: string, email: string, teams: string[]}>|null */
    private ?array $rows = null;

    public function __construct(private readonly PDO $pdo, private readonly Config $config) {}

    public function all(): array
    {
        if ($this->rows !== null) return $this->rows;

        $this->rows = [];
        foreach ($this->pdo->query("SELECT name, email FROM portal_users ORDER BY id")->fetchAll() as $row) {
            $this->rows[] = [
                'name' => $row['name'],
                'email' => $row['email'],
                'teams' => $this->config->teamsOf($row['email']),
            ];
        }
        return $this->rows;
    }

    public function nameOf(string $email): string
    {
        foreach ($this->all() as $member) {
            if (strcasecmp($member['email'], $email) === 0) return $member['name'];
        }
        return '';
    }

    public function has(string $email): bool
    {
        foreach ($this->all() as $member) {
            if (strcasecmp($member['email'], $email) === 0) return true;
        }
        return false;
    }
}

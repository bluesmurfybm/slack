<?php

namespace Dti\Controller;

use Dti\Config;
use Dti\Http\ApiException;
use Dti\Http\Response;
use Dti\Identity\Identity;

final class ScoreController
{
    public function __construct(
        private readonly Config $config,
        private readonly \PDO $pdo,
        private readonly array $members,
        private readonly Identity $identity,
    ) {}

    public function index(array $query): Response
    {
        if (!$this->config->isAdmin($this->identity->email)) {
            throw new ApiException('관리자만 할 수 있습니다', 403);
        }

        $start = $this->dateParam($query['start'] ?? '');
        $end = $this->dateParam($query['end'] ?? '');

        return Response::json(dti_score_summary($this->pdo, $this->members, $start, $end));
    }

    private function dateParam(mixed $value): string
    {
        $date = trim((string)$value);
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ApiException('기간은 YYYY-MM-DD 형식이어야 합니다', 422);
        }
        return $date;
    }
}

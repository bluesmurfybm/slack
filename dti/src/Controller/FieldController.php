<?php

namespace Dti\Controller;

use Dti\Config;
use Dti\Http\ApiException;
use Dti\Http\Input;
use Dti\Http\Response;

final class FieldController
{
    public function __construct(
        private readonly Config $config,
        private readonly \PDO $pdo,
        private readonly array $identity,
    ) {}

    public function index(): Response
    {
        return Response::json(dti_field_all($this->pdo));
    }

    public function create(array $body): Response
    {
        $this->requireAdmin();

        $name = Input::str($body, 'name', '분야 이름', required: true);
        if (dti_field_exists($this->pdo, $name)) {
            throw new ApiException('이미 있는 분야입니다', 409);
        }

        return Response::json(['id' => dti_field_insert($this->pdo, $name), 'name' => $name], 201);
    }

    public function destroy(int $fid): Response
    {
        $this->requireAdmin();

        if (!dti_field_delete($this->pdo, $fid)) {
            throw new ApiException('없는 분야입니다', 404);
        }
        return Response::json(['ok' => true]);
    }

    private function requireAdmin(): void
    {
        if (!$this->config->isAdmin($this->identity['email'])) {
            throw new ApiException('관리자만 할 수 있습니다', 403);
        }
    }
}

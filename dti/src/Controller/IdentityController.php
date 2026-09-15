<?php

namespace Dti\Controller;

use Dti\Config;
use Dti\Http\Response;
use Dti\Identity\Identity;
use Dti\Identity\Members;

final class IdentityController
{
    public function __construct(
        private readonly Config $config,
        private readonly Members $members,
        private readonly ?Identity $identity,
    ) {}

    public function whoami(): Response
    {
        $email = $this->identity?->email;

        return Response::json([
            'email' => $email,
            'name' => $this->identity?->name,
            'color' => $this->identity?->color,
            'is_admin' => $this->config->isAdmin($email),
            'teams' => $this->config->teamsOf($email),
            'all_teams' => Config::TEAMS,
            'all_magazines' => Config::MAGAZINES,
            // 개발 로그인은 없앴지만 키는 남긴다 — core.js 가 401 분기에서 읽는다
            'dev_login' => false,
            'dev_accounts' => [],
            'portal_url' => $this->config->portalUrl,
            'slack_url' => $this->config->slackUrl,
        ]);
    }

    public function members(): Response
    {
        return Response::json($this->members->all());
    }
}

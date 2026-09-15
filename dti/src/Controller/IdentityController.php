<?php

namespace Dti\Controller;

use Dti\Config;
use Dti\Http\Response;

final class IdentityController
{
    public function __construct(
        private readonly Config $config,
        private readonly \PDO $pdo,
        private readonly ?array $identity,
    ) {}

    public function whoami(): Response
    {
        $email = $this->identity['email'] ?? null;

        return Response::json([
            'email' => $email,
            'name' => $this->identity['name'] ?? null,
            'color' => $this->identity['color'] ?? null,
            'is_admin' => $this->config->isAdmin($email),
            'teams' => $this->config->teamsOf($email),
            'all_teams' => Config::TEAMS,
            'all_magazines' => Config::MAGAZINES,
            'portal_url' => $this->config->portalUrl,
            'slack_url' => $this->config->slackUrl,
        ]);
    }

    public function members(): Response
    {
        return Response::json(dti_members_all($this->pdo, $this->config));
    }
}

<?php

namespace Dti\Controller;

use Dti\Http\Response;

final class IdentityController
{
    public function __construct(
        private readonly array $config,
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
            'is_admin' => dti_is_admin($this->config, $email),
            'teams' => dti_teams_of($this->config, $email),
            'all_teams' => DTI_TEAMS,
            'all_magazines' => DTI_MAGAZINES,
            'portal_url' => $this->config['portal_url'],
            'slack_url' => $this->config['slack_url'],
        ]);
    }

    public function members(): Response
    {
        return Response::json(dti_members_all($this->pdo, $this->config));
    }
}

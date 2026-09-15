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
            'portal_url' => $this->config->portalUrl,
            'slack_url' => $this->config->slackUrl,
        ]);
    }

    public function members(): Response
    {
        return Response::json($this->members->all());
    }
}

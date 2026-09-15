<?php

use Dti\Http\ApiException;
use Dti\Http\Response;

function dti_route_identity(array $ctx, array $req): Response {
    $identity = $ctx['identity'];
    $email = $identity['email'] ?? null;

    if (($req['seg'][0] ?? '') === 'whoami') {
        return Response::json([
            'email' => $email,
            'name' => $identity['name'] ?? null,
            'color' => $identity['color'] ?? null,
            'is_admin' => dti_is_admin($ctx['config'], $email),
            'teams' => dti_teams_of($ctx['config'], $email),
            'all_teams' => DTI_TEAMS,
            'all_magazines' => DTI_MAGAZINES,
            'portal_url' => $ctx['config']['portal_url'],
            'slack_url' => $ctx['config']['slack_url'],
        ]);
    }

    return Response::json(dti_members_all($ctx['pdo'], $ctx['config']));
}

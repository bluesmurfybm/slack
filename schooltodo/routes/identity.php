<?php

/**
 * @param array $ctx
 * @return array
 */
function schooltodo_route_whoami($ctx) {
    $identity = $ctx['identity'];

    return schooltodo_json([
        'email' => $identity['email'] ?? null,
        'name' => $identity['name'] ?? null,
        'statuses' => SCHOOLTODO_STATUSES,
        'portal_url' => $ctx['config']['portal_url'],
    ]);
}

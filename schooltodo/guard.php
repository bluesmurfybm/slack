<?php

/**
 * @param array $config
 * @param string $apiKey
 * @return array|null
 */
function schooltodo_identity($config, $apiKey) {
    if ($apiKey !== '') return schooltodo_bot_identity($config, $apiKey);

    return schooltodo_session_identity();
}

/**
 * @param array $config
 * @param string $apiKey
 * @return array|null
 */
function schooltodo_bot_identity($config, $apiKey) {
    if ($config['bot_key'] === '' || !hash_equals($config['bot_key'], $apiKey)) return null;

    return ['email' => '', 'name' => SCHOOLTODO_BOT_NAME, 'color' => '', 'bot' => true];
}

/**
 * @return array|null
 */
function schooltodo_session_identity() {
    require_once __DIR__ . '/../core/auth.php';

    $user = current_portal_user();
    if (!$user) return null;

    return [
        'email' => $user['email'],
        'name' => $user['name'] ?? '',
        'color' => user_color($user),
        'bot' => false,
    ];
}

/**
 * @return array
 */
function schooltodo_page_begin() {
    $identity = schooltodo_session_identity();
    if (!$identity) {
        header('Location: ../index.php?need_login=schooltodo');
        exit;
    }
    $config = schooltodo_config_from_portal();

    return [
        'identity' => $identity,
        'portal' => rtrim($config['portal_url'], '/'),
    ];
}

/**
 * @param string $rel
 * @return string
 */
function schooltodo_asset($rel) {
    $mtime = @filemtime(__DIR__ . '/' . $rel);
    return $rel . '?v=' . ($mtime ?: 0);
}

/**
 * @param string|null $s
 * @return string
 */
function schooltodo_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

<?php
/**
 * 구성원 조회 어댑터.
 *
 * 역할 배정 화면에서 "구성원 리스트 중에서 선택"하려면 iworks 회원
 * 테이블을 읽어야 합니다. 테이블/컬럼 이름은 config.php 의
 * iworks.member 에서 지정합니다. 컬럼명은 식별자라 바인딩이 안 되므로
 * 화이트리스트 검증을 거쳐 사용합니다.
 */

declare(strict_types=1);

function bc_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('허용되지 않는 식별자: ' . $name);
    }
    return '`' . $name . '`';
}

function bc_directory_select(): string
{
    $m = bc_config('iworks.member');
    $cols = [
        bc_ident($m['col_id'])    . ' AS id',
        bc_ident($m['col_name'])  . ' AS name',
        ($m['col_email'] ? bc_ident($m['col_email']) : 'NULL') . ' AS email',
        ($m['col_slack_id'] ? bc_ident($m['col_slack_id']) : 'NULL') . ' AS slack_id',
    ];
    return 'SELECT ' . implode(', ', $cols) . ' FROM ' . bc_ident($m['table']);
}

/**
 * 재직 중인 구성원 전체 목록.
 *
 * @return array<int,array{id:string,name:string,email:?string,slack_id:?string}>
 */
function bc_directory_all(string $keyword = ''): array
{
    $m     = bc_config('iworks.member');
    $sql   = bc_directory_select();
    $where = [];
    $args  = [];

    if (!empty($m['active_where'])) {
        $where[] = '(' . $m['active_where'] . ')';
    }
    if ($keyword !== '') {
        $where[] = '(' . bc_ident($m['col_name']) . ' LIKE ? OR '
                       . bc_ident($m['col_id'])   . ' LIKE ?)';
        $args[] = '%' . $keyword . '%';
        $args[] = '%' . $keyword . '%';
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . bc_ident($m['col_name']) . ' ASC LIMIT 500';

    try {
        return bc_fetch_all($sql, $args);
    } catch (PDOException $e) {
        // 회원 테이블 매핑이 아직 안 맞는 경우 빈 목록으로 떨어뜨린다.
        error_log('[BlueCart] directory query failed: ' . $e->getMessage());
        return [];
    }
}

/** 단일 구성원 조회. */
function bc_directory_find(string $userId): ?array
{
    $m   = bc_config('iworks.member');
    $sql = bc_directory_select() . ' WHERE ' . bc_ident($m['col_id']) . ' = ? LIMIT 1';
    try {
        return bc_fetch_one($sql, [$userId]);
    } catch (PDOException $e) {
        error_log('[BlueCart] directory find failed: ' . $e->getMessage());
        return null;
    }
}

/** 여러 명 한 번에. id => row */
function bc_directory_map(array $userIds): array
{
    $userIds = array_values(array_unique(array_filter($userIds)));
    if (!$userIds) {
        return [];
    }
    $m    = bc_config('iworks.member');
    $ph   = implode(',', array_fill(0, count($userIds), '?'));
    $sql  = bc_directory_select() . ' WHERE ' . bc_ident($m['col_id']) . " IN ($ph)";
    try {
        $rows = bc_fetch_all($sql, $userIds);
    } catch (PDOException $e) {
        error_log('[BlueCart] directory map failed: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[$r['id']] = $r;
    }
    return $out;
}

<?php
declare(strict_types=1);

final class Category
{
    public static function all(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM bc_category';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        return bc_fetch_all($sql);
    }

    public static function find(int $id): ?array
    {
        return bc_fetch_one('SELECT * FROM bc_category WHERE id = ?', [$id]);
    }

    public static function create(string $code, string $name, int $sortOrder = 0): int
    {
        bc_query(
            'INSERT INTO bc_category (code, name, sort_order, is_active) VALUES (?, ?, ?, 1)',
            [$code, $name, $sortOrder]
        );
        return (int)bc_db()->lastInsertId();
    }

    public static function update(int $id, string $name, int $sortOrder, bool $isActive): void
    {
        bc_query(
            'UPDATE bc_category SET name = ?, sort_order = ?, is_active = ? WHERE id = ?',
            [$name, $sortOrder, $isActive ? 1 : 0, $id]
        );
    }

    /**
     * 삭제는 사용 중이면 막는다. 이력 보존이 우선이라 비활성화를 권장.
     */
    public static function delete(int $id): bool
    {
        $used = (int)bc_fetch_value(
            'SELECT COUNT(*) FROM bc_request WHERE category_id = ?', [$id], 0
        );
        if ($used > 0) {
            return false;
        }
        bc_query('DELETE FROM bc_category WHERE id = ?', [$id]);
        return true;
    }
}

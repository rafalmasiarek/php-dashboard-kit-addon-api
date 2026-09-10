<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Admin;

use PDO;

/**
 * Manages the many-to-many assignment of API scopes to users.
 *
 * A user may only create tokens with scopes that have been explicitly assigned
 * to them by an administrator. Admins bypass this restriction when creating
 * tokens from the admin panel.
 *
 * @package rafalmasiarek\DashboardKitApi\Admin
 */
final class UserScopeRepository
{
    /**
     * PDO connection.
     *
     * @var PDO
     */
    private PDO $pdo;

    /**
     * @param PDO $pdo Active database connection.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Return all scope IDs assigned to a user.
     *
     * @param  string  $userId User UUID.
     * @return int[]           List of scope IDs.
     */
    public function scopeIdsForUser(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT scope_id FROM user_scopes WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return \array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
    }

    /**
     * Return all scope rows assigned to a user, enriched with name and description.
     *
     * @param  string  $userId User UUID.
     * @return array<int, array{id: int, name: string, description: string|null, category: string, action: string}>
     */
    public function scopesForUser(string $userId): array
    {
        $sql = '
            SELECT s.id, s.name, s.description
            FROM user_scopes us
            JOIN scopes s ON s.id = us.scope_id
            WHERE us.user_id = :uid
            ORDER BY s.name ASC
        ';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return \array_map([$this, 'enrichRow'], $rows);
    }

    /**
     * Replace all scope assignments for a user with the given set of scope IDs.
     *
     * Deletes existing assignments and inserts the new ones in a single transaction.
     *
     * @param string $userId   User UUID.
     * @param int[]  $scopeIds New set of scope IDs to assign.
     * @return void
     */
    public function setForUser(string $userId, array $scopeIds): void
    {
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM user_scopes WHERE user_id = :uid');
            $del->execute([':uid' => $userId]);

            if ($scopeIds !== []) {
                $ins = $this->pdo->prepare('INSERT INTO user_scopes (user_id, scope_id) VALUES (:uid, :sid)');
                foreach (\array_unique($scopeIds) as $scopeId) {
                    $ins->execute([':uid' => $userId, ':sid' => (int) $scopeId]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Check whether a user has a specific scope assigned by name.
     *
     * @param string $userId    User UUID.
     * @param string $scopeName Full scope name, e.g. 'notes:read'.
     * @return bool
     */
    public function userHasScopeByName(string $userId, string $scopeName): bool
    {
        $sql  = 'SELECT COUNT(*) FROM user_scopes us JOIN scopes s ON s.id = us.scope_id WHERE us.user_id = :uid AND s.name = :name';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId, ':name' => $scopeName]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Derive category and action from a raw DB row.
     *
     * @param  array<string, mixed> $row Raw PDO row with id, name, description.
     * @return array{id: int, name: string, description: string|null, category: string, action: string}
     */
    private function enrichRow(array $row): array
    {
        $name        = (string) $row['name'];
        $colonPos    = \strpos($name, ':');
        $category    = $colonPos !== false ? \substr($name, 0, $colonPos)      : $name;
        $action      = $colonPos !== false ? \substr($name, $colonPos + 1)     : $name;
        $description = isset($row['description']) && $row['description'] !== '' && $row['description'] !== null
            ? (string) $row['description']
            : null;

        return [
            'id'          => (int) $row['id'],
            'name'        => $name,
            'description' => $description,
            'category'    => $category,
            'action'      => $action,
        ];
    }
}

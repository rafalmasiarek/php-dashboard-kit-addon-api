<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Admin;

use rafalmasiarek\DashboardKit\Model\Model;

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
     * Return all scope IDs assigned to a user.
     *
     * @param  string  $userId User UUID.
     * @return int[]           List of scope IDs.
     */
    public function scopeIdsForUser(string $userId): array
    {
        $ids = Model::on('user_scopes')->select('scope_id')->where('user_id', $userId)->get()->pluck('scope_id');
        return \array_map('intval', $ids);
    }

    /**
     * Return all scope rows assigned to a user, enriched with name and description.
     *
     * @param  string  $userId User UUID.
     * @return array<int, array{id: int, name: string, description: string|null, category: string, action: string}>
     */
    public function scopesForUser(string $userId): array
    {
        $rows = Model::on('user_scopes')
            ->select('scopes.id', 'scopes.name', 'scopes.description')
            ->join('scopes', 'scopes.id', '=', 'user_scopes.scope_id')
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get()
            ->toArray();

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
        Model::transaction(function () use ($userId, $scopeIds): void {
            Model::on('user_scopes')->where('user_id', $userId)->forceDelete();

            foreach (\array_unique($scopeIds) as $scopeId) {
                Model::on('user_scopes')->insert(['user_id' => $userId, 'scope_id' => (int) $scopeId]);
            }
        });
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
        return Model::on('user_scopes')
            ->join('scopes', 'scopes.id', '=', 'user_scopes.scope_id')
            ->where('user_id', $userId)
            ->where('name', $scopeName)
            ->count() > 0;
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

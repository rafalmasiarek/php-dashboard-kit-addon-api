<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Admin;

use rafalmasiarek\DashboardKit\Model\Model;

/**
 * Read/write access to the API scope dictionary table.
 *
 * Scopes follow the convention `category:action` (e.g. `notes:read`, `cron:run`).
 * The `category` and `action` derived fields are computed on the fly from the stored
 * `name` by splitting on the first colon.
 *
 * @package rafalmasiarek\DashboardKitApi\Admin
 */
final class ApiScopeRepository
{
    /**
     * Scopes table name.
     *
     * @var string
     */
    private string $tblScopes;

    /**
     * @param string $tblScopes Scopes table name. Default: 'scopes'.
     */
    public function __construct(string $tblScopes = 'scopes')
    {
        $this->tblScopes = $tblScopes;
    }

    /**
     * Return all scope rows enriched with derived category and action fields.
     *
     * Each returned item has the shape:
     *   id          (int)
     *   name        (string)  full name, e.g. 'notes:read'
     *   description (string|null)
     *   category    (string)  part before first colon, e.g. 'notes'
     *   action      (string)  part after first colon, e.g. 'read'; equals name when no colon
     *
     * @return array<int, array{id: int, name: string, description: string|null, category: string, action: string}>
     */
    public function all(): array
    {
        $rows = Model::on($this->tblScopes)
            ->select('id', 'name', 'description')
            ->orderBy('name')
            ->get()
            ->toArray();

        return \array_map([$this, 'enrichRow'], $rows);
    }

    /**
     * Return scopes grouped by category.
     *
     * Returns a dict keyed by category string, where each value is a list of scope
     * rows (same shape as {@see all()}) that belong to that category.
     *
     * @return array<string, array<int, array{id: int, name: string, description: string|null, category: string, action: string}>>
     */
    public function allGrouped(): array
    {
        $grouped = [];
        foreach ($this->all() as $scope) {
            $grouped[$scope['category']][] = $scope;
        }
        return $grouped;
    }

    /**
     * Insert a new scope. Silently ignores duplicates (INSERT IGNORE).
     *
     * @param string      $name        Full scope name, e.g. 'notes:read'.
     * @param string|null $description Optional human-readable description.
     * @return void
     */
    public function create(string $name, ?string $description = null): void
    {
        Model::on($this->tblScopes)->insertOrIgnore(['name' => $name, 'description' => $description]);
    }

    /**
     * Delete a scope by its primary key.
     *
     * @param int $id Scope row ID.
     * @return bool True when a row was actually deleted.
     */
    public function deleteById(int $id): bool
    {
        return Model::on($this->tblScopes)->where('id', $id)->forceDelete() > 0;
    }

    /**
     * Check whether a scope name already exists.
     *
     * @param string $name Full scope name to check.
     * @return bool
     */
    public function exists(string $name): bool
    {
        return Model::on($this->tblScopes)->where('name', $name)->count() > 0;
    }

    /**
     * Return just the scope name strings, sorted alphabetically.
     *
     * @return string[]
     */
    public function allNames(): array
    {
        $names = Model::on($this->tblScopes)->select('name')->orderBy('name')->get()->pluck('name');
        return \array_values(\array_map('strval', $names));
    }

    /**
     * Derive category and action from a raw DB row and return the enriched shape.
     *
     * @param array<string, mixed> $row Raw PDO row with id, name, description.
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

<?php

namespace app\model\channel;

use Illuminate\Database\Query\Expression;

class TableRecord extends BaseModel
{
    protected $guarded = [];
    public $timestamps = false;

    public static function queryTable(string $table): mixed
    {
        $model = new static();
        $model->setTable($table);
        return $model->newQuery();
    }

    public static function table(string $table): mixed
    {
        return self::queryTable($table);
    }

    public static function connection(): mixed
    {
        return (new static())->getConnection();
    }

    public static function operationLogTables(string $database): array
    {
        return self::queryTable('information_schema.tables')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'like', 'operation_log\_%')
            ->orderByDesc('TABLE_NAME')
            ->pluck('TABLE_NAME')
            ->filter(static fn ($table): bool => is_string($table) && preg_match('/^operation_log_\d{6}$/', $table))
            ->values()
            ->all();
    }

    public static function operationLogQuery(string $table): mixed
    {
        return self::queryTable($table)
            ->leftJoin('account', "{$table}.account_id", '=', 'account.id')
            ->leftJoin('users', 'account.user_id', '=', 'users.id')
            ->whereNull("{$table}.deleted_at");
    }

    public static function operationLogColumns(string $table): array
    {
        return [
            "{$table}.id",
            "{$table}.uuid",
            "{$table}.account_id",
            "{$table}.action",
            "{$table}.ip",
            "{$table}.payload",
            "{$table}.created_at",
            'account.login_name',
            'users.name as user_name',
            new Expression("'" . str_replace("'", "''", $table) . "' as source_table"),
        ];
    }

    public static function archiveRows(string $table, array $columns, array $order): array
    {
        $query = self::queryTable($table)->whereNull('deleted_at');
        foreach ($order as $field) {
            $query->orderBy($field);
        }

        return $query->get($columns)->map(static fn ($row): array => (array) $row)->all();
    }

    public static function updateArchiveRow(string $table, string $idField, int $id, array $values): int
    {
        return self::queryTable($table)
            ->where($idField, $id)
            ->whereNull('deleted_at')
            ->update($values);
    }

    public static function insertArchiveRow(string $table, array $values): bool
    {
        return self::queryTable($table)->insert($values);
    }

    public static function softDeleteArchiveRow(string $table, string $idField, int $id, string $now): int
    {
        return self::queryTable($table)
            ->where($idField, $id)
            ->whereNull('deleted_at')
            ->update([
                'flag' => 'off',
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public static function enabledOptionRows(string $table, array $columns, array $order, ?string $flagField = 'flag'): array
    {
        $query = self::queryTable($table)->whereNull('deleted_at');
        if ($flagField) {
            $query->where($flagField, 'on');
        }
        foreach ($order as $field) {
            $query->orderBy($field);
        }

        return $query->get($columns)->map(static fn ($row): array => (array) $row)->all();
    }

    public static function latestDesktopConfig(int $accountId, array $columns = ['id', 'layout_json']): ?object
    {
        return self::queryTable('user_desktop_config')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first($columns);
    }

    public static function saveDesktopConfig(int $accountId, array $values): void
    {
        $row = self::latestDesktopConfig($accountId, ['id']);
        if ($row) {
            self::queryTable('user_desktop_config')
                ->where('id', $row->id)
                ->update($values);
            return;
        }

        self::queryTable('user_desktop_config')->insert(array_merge($values, [
            'account_id' => $accountId,
            'created_at' => $values['updated_at'] ?? date('Y-m-d H:i:s'),
        ]));
    }

    public static function notifySettings(int $accountId): array
    {
        return self::queryTable('user_notify_setting')
            ->where('account_id', $accountId)
            ->where('msg_type', 'system')
            ->whereNull('deleted_at')
            ->get(['channel', 'enabled'])
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public static function saveNotifySetting(int $accountId, string $channel, bool $enabled, string $now): void
    {
        $row = self::queryTable('user_notify_setting')
            ->where('account_id', $accountId)
            ->where('msg_type', 'system')
            ->where('channel', $channel)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first(['id']);
        $values = [
            'enabled' => $enabled ? 'true' : 'false',
            'updated_at' => $now,
        ];

        if ($row) {
            self::queryTable('user_notify_setting')
                ->where('id', $row->id)
                ->update($values);
            return;
        }

        self::queryTable('user_notify_setting')->insert(array_merge($values, [
            'account_id' => $accountId,
            'msg_type' => 'system',
            'channel' => $channel,
            'created_at' => $now,
        ]));
    }
}

<?php

namespace app\model\system;

class Database extends BaseModel
{
    protected $table = 'databases';
    protected $primaryKey = 'database_id';
    protected $guarded = [];

    /**
     * 后台进程用：按 database_id 取一个启用学校库的连接配置（含学校标识）。
     */
    public static function connectionConfigById(int $databaseId): ?array
    {
        if ($databaseId <= 0) {
            return null;
        }

        $row = self::query()
            ->from('databases')
            ->leftJoin('authorizations', 'authorizations.database_id', '=', 'databases.database_id')
            ->leftJoin('schools', 'schools.school_id', '=', 'authorizations.school_id')
            ->where('databases.database_id', $databaseId)
            ->where('databases.status', 'enabled')
            ->orderBy('authorizations.authorization_id')
            ->first(self::configFields());

        return $row ? $row->toArray() : null;
    }

    /**
     * 后台进程用：列出所有启用的学校库连接配置（仅连接字段，供扫描任务重排队用）。
     */
    public static function enabledConnectionConfigs(): array
    {
        return self::query()
            ->from('databases')
            ->where('databases.status', 'enabled')
            ->orderBy('databases.database_id')
            ->get([
                'databases.database_id',
                'databases.database_host',
                'databases.database_port',
                'databases.database_user',
                'databases.database_pwd',
                'databases.database_db',
                'databases.database_charset',
                'databases.database_prefix',
                'databases.config_version',
            ])
            ->map(static fn ($row): array => (array) $row->toArray())
            ->all();
    }

    private static function configFields(): array
    {
        return [
            'databases.database_id',
            'databases.database_host',
            'databases.database_port',
            'databases.database_user',
            'databases.database_pwd',
            'databases.database_db',
            'databases.database_charset',
            'databases.database_prefix',
            'databases.config_version',
            'authorizations.school_id',
            'schools.school_code',
            'schools.school_name',
        ];
    }
}

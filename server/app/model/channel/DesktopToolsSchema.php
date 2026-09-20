<?php

namespace app\model\channel;

use Illuminate\Database\Schema\Blueprint;

/** 桌面工具的可重复执行结构升级。 */
class DesktopToolsSchema extends TableRecord
{
    /** 仅补充缺失字段和表，不删除历史数据。 */
    public static function apply(): void
    {
        $schema = self::connection()->getSchemaBuilder();
        foreach (['scope', 'open_mode', 'updated_by', 'revision', 'request_config'] as $column) {
            if ($schema->hasColumn('favorite_link', $column)) continue;
            $schema->table('favorite_link', function (Blueprint $table) use ($column): void {
                match ($column) {
                    'scope' => $table->string('scope', 20)->default('personal'),
                    'open_mode' => $table->string('open_mode', 20)->default('client'),
                    'updated_by' => $table->unsignedBigInteger('updated_by')->nullable(),
                    'revision' => $table->unsignedInteger('revision')->default(1),
                    'request_config' => $table->json('request_config')->nullable(),
                };
            });
        }
        if (!$schema->hasIndex('favorite_link', 'idx_scope_status')) {
            $schema->table('favorite_link', fn (Blueprint $table) => $table->index(['scope', 'status', 'deleted_at'], 'idx_scope_status'));
        }
        foreach (self::creationStatements() as $statement) self::connection()->unprepared($statement);
    }

    /** 读取随代码审核的固定建表语句。 */
    public static function creationStatements(): array
    {
        $sql = file_get_contents(base_path('database/updates/0.2.0-school-tools.sql'));
        return array_values(array_filter(array_map('trim', explode(';', $sql)), fn ($sql) => str_starts_with($sql, 'CREATE TABLE')));
    }
}

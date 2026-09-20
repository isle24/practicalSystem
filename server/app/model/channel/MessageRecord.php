<?php

namespace app\model\channel;

use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use PDO;

class MessageRecord extends TableRecord
{
    public const TYPES = ['system', 'alert', 'audit', 'todo', 'result'];
    public const LEVELS = ['normal', 'important', 'urgent'];

    private static array $schemaReady = [];

    public static function inboxPage(int $accountId, array $filters): array
    {
        self::ensureSchema();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::applyInboxFilters(self::inboxQuery($accountId), $filters);
        $total = (int) (clone $query)->count();
        $rows = $query
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id')
            ->forPage($page, $pageSize)
            ->get(self::inboxColumns())
            ->map(static fn ($row): array => self::messageRow($row))
            ->all();

        return [
            'items' => $rows,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
        ];
    }

    public static function unreadSummary(int $accountId): array
    {
        self::ensureSchema();

        $query = self::inboxQuery($accountId)->where('mt.is_read', 0);
        $byType = array_fill_keys(self::TYPES, 0);
        $rows = (clone $query)
            ->groupBy('m.type')
            ->get(['m.type', new Expression('COUNT(*) as total')]);

        foreach ($rows as $row) {
            $type = self::normalizeType((string) ($row->type ?? 'system'));
            $byType[$type] = (int) ($row->total ?? 0);
        }

        return [
            'unread' => (int) (clone $query)->count(),
            'by_type' => $byType,
        ];
    }

    public static function markRead(int $accountId, array $targetIds, bool $all, string $now): int
    {
        self::ensureSchema();

        $query = self::queryTable('message_target')
            ->where('account_id', $accountId)
            ->where('is_read', 0)
            ->whereNull('deleted_at');

        if (!$all) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $targetIds))));
            if (!$ids) {
                return 0;
            }
            $query->whereIn('id', $ids);
        }

        return self::connection()->transaction(function () use ($query, $accountId, $now): int {
            $count = (int) $query->update([
                'is_read' => 1,
                'read_at' => $now,
                'updated_at' => $now,
            ]);
            if ($count > 0) MessageRealtimeRecord::enqueueNotification([$accountId]);
            return $count;
        });
    }

    public static function createMessage(array $message, array $accountIds, string $now): int
    {
        self::ensureSchema();

        $targets = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if (!$targets) {
            throw new InvalidArgumentException('缺少消息接收人');
        }

        return (int) self::connection()->transaction(function () use ($message, $targets, $now): int {
            $senderId = (int) ($message['sender_id'] ?? 0);
            $messageId = (int) self::queryTable('message')->insertGetId([
                'uuid' => self::uuidValue(),
                'name' => mb_substr((string) ($message['title'] ?? ''), 0, 180),
                'code' => $message['code'] ?? null,
                'title' => mb_substr((string) ($message['title'] ?? '系统消息'), 0, 180),
                'content' => (string) ($message['content'] ?? ''),
                'type' => self::normalizeType((string) ($message['type'] ?? 'system')),
                'sender_id' => (int) ($message['sender_id'] ?? 0),
                'sender_name' => mb_substr((string) ($message['sender_name'] ?? '系统'), 0, 80),
                'level' => self::normalizeLevel((string) ($message['level'] ?? 'normal')),
                'entity_type' => self::nullableString($message['entity_type'] ?? null, 80),
                'entity_id' => self::nullableInt($message['entity_id'] ?? null),
                'link_url' => self::nullableString($message['link_url'] ?? null, 500),
                'metadata' => self::jsonValue($message['metadata'] ?? []),
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);

            $targetRows = [];
            $logRows = [];
            foreach ($targets as $accountId) {
                $isSender = $senderId > 0 && $accountId === $senderId;
                $targetRows[] = [
                    'uuid' => self::uuidValue(),
                    'message_id' => $messageId,
                    'account_id' => $accountId,
                    'is_read' => $isSender ? 1 : 0,
                    'read_at' => $isSender ? $now : null,
                    'status' => 'enabled',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ];
                $logRows[] = [
                    'uuid' => self::uuidValue(),
                    'message_id' => $messageId,
                    'account_id' => $accountId,
                    'channel' => 'internal',
                    'status' => 'sent',
                    'error_message' => null,
                    'sent_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ];
            }

            self::queryTable('message_target')->insert($targetRows);
            self::queryTable('message_channel_log')->insert($logRows);
            MessageRealtimeRecord::enqueueNotification($targets);

            return $messageId;
        });
    }

    /**
     * 判断指定模板和业务标识的消息是否已经发送。
     */
    public static function messageExists(string $code, string $entityType, int $entityId): bool
    {
        self::ensureSchema();

        return self::queryTable('message')
            ->where('code', $code)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->exists();
    }

    public static function templateByCode(string $code): ?array
    {
        self::ensureSchema();

        $row = self::queryTable('message_template')
            ->where('code', $code)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first(self::templateColumns());

        if (!$row) {
            self::seedDefaultTemplates(true);
            $row = self::queryTable('message_template')
                ->where('code', $code)
                ->where('status', 'enabled')
                ->whereNull('deleted_at')
                ->first(self::templateColumns());
        }

        return $row ? self::templateRow($row) : null;
    }

    public static function templatePage(array $filters): array
    {
        self::ensureSchema();
        self::seedDefaultTemplates(true);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($filters['page_size'] ?? 20)));
        $query = self::templateQuery($filters);
        $total = (int) (clone $query)->count();
        $rows = $query
            ->orderByDesc('is_system')
            ->orderBy('sort')
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get(self::templateColumns())
            ->map(static fn ($row): array => self::templateRow($row))
            ->all();

        return [
            'items' => $rows,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
        ];
    }

    public static function saveTemplate(array $values, string $now): int
    {
        self::ensureSchema();

        $id = (int) ($values['id'] ?? 0);
        $existing = $id > 0
            ? self::queryTable('message_template')->where('id', $id)->whereNull('deleted_at')->first(['is_system'])
            : null;
        $data = [
            'name' => mb_substr((string) ($values['name'] ?? ''), 0, 180),
            'code' => mb_substr((string) ($values['code'] ?? ''), 0, 120),
            'title_tpl' => mb_substr((string) ($values['title_tpl'] ?? ''), 0, 255),
            'content_tpl' => (string) ($values['content_tpl'] ?? ''),
            'type' => self::normalizeType((string) ($values['type'] ?? 'system')),
            'level' => self::normalizeLevel((string) ($values['level'] ?? 'normal')),
            'description' => self::nullableString($values['description'] ?? null, 500),
            'variables' => self::jsonValue($values['variables'] ?? []),
            'link_url_tpl' => self::nullableString($values['link_url_tpl'] ?? null, 500),
            'channels' => self::jsonValue($values['channels'] ?? ['internal']),
            'is_system' => $existing ? (int) ($existing->is_system ?? 0) : 0,
            'sort' => (int) ($values['sort'] ?? 100),
            'status' => (string) ($values['status'] ?? 'enabled'),
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        if ($id > 0) {
            self::queryTable('message_template')->where('id', $id)->update($data);
            return $id;
        }

        return (int) self::queryTable('message_template')->insertGetId(array_merge($data, [
            'uuid' => self::uuidValue(),
            'created_at' => $now,
        ]));
    }

    public static function deleteTemplate(int $id, string $now): int
    {
        self::ensureSchema();

        return (int) self::queryTable('message_template')
            ->where('id', $id)
            ->where('is_system', 0)
            ->whereNull('deleted_at')
            ->update([
                'status' => 'disabled',
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public static function normalizeType(string $type): string
    {
        return in_array($type, self::TYPES, true) ? $type : 'system';
    }

    public static function normalizeLevel(string $level): string
    {
        return in_array($level, self::LEVELS, true) ? $level : 'normal';
    }

    public static function ensureSchema(): void
    {
        $connection = self::connection();
        $key = method_exists($connection, 'getDatabaseName') ? (string) $connection->getDatabaseName() : spl_object_hash($connection);
        if (isset(self::$schemaReady[$key])) {
            self::seedDefaultTemplates();
            return;
        }

        if ($connection->transactionLevel() > 0) {
            foreach (['message', 'message_target', 'message_template', 'message_channel_log'] as $table) {
                if (!$connection->getSchemaBuilder()->hasTable($table)) throw new \RuntimeException('消息表未初始化，请先升级学校数据库');
            }
            self::seedDefaultTemplates();
            return;
        }

        self::createSchema();
        self::seedDefaultTemplates();
        self::$schemaReady[$key] = true;
    }

    public static function seedDefaultTemplates(bool $force = false): void
    {
        self::ensureDefaultTemplates(self::connection(), $force);
    }

    public static function syncDefaultTemplates(): array
    {
        self::ensureSchema();
        self::seedDefaultTemplates(true);

        $total = (int) self::queryTable('message_template')->whereNull('deleted_at')->count();
        $systemTotal = (int) self::queryTable('message_template')
            ->where('is_system', 1)
            ->whereNull('deleted_at')
            ->count();

        return [
            'total' => $total,
            'system_total' => $systemTotal,
            'default_total' => count(self::defaultTemplates()),
        ];
    }

    public static function ensureDefaultTemplates(mixed $connection, bool $force = false): void
    {
        static $seeded = [];

        $key = method_exists($connection, 'getDatabaseName') ? (string) $connection->getDatabaseName() : spl_object_hash($connection);
        if (!$force && isset($seeded[$key])) {
            return;
        }

        if ($connection instanceof PDO) {
            self::ensureDefaultTemplatesWithPdo($connection);
            $seeded[$key] = true;
            return;
        }

        $now = date('Y-m-d H:i:s');
        foreach (self::defaultTemplates() as $template) {
            $existing = $connection->table('message_template')->where('code', $template['code'])->first(['id', 'deleted_at']);
            if ($existing) {
                $updates = ['is_system' => 1];
                if (!empty($existing->deleted_at)) {
                    $updates['status'] = 'enabled';
                    $updates['deleted_at'] = null;
                    $updates['updated_at'] = $now;
                }
                $connection->table('message_template')->where('id', (int) $existing->id)->update($updates);
                continue;
            }

            $connection->table('message_template')->insertOrIgnore(self::defaultTemplateRow($template, $now));
        }

        $seeded[$key] = true;
    }

    private static function ensureDefaultTemplatesWithPdo(PDO $pdo): void
    {
        self::ensureDefaultTemplatePdoSchema($pdo);

        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO `message_template` (
                `uuid`, `name`, `code`, `title_tpl`, `content_tpl`, `type`, `level`,
                `description`, `variables`, `link_url_tpl`, `channels`, `is_system`, `sort`, `status`,
                `created_at`, `updated_at`, `deleted_at`
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 'enabled', ?, ?, NULL)"
        );
        $restoreStmt = $pdo->prepare(
            "UPDATE `message_template`
             SET `is_system` = 1,
                 `status` = IF(`deleted_at` IS NULL, `status`, 'enabled'),
                 `deleted_at` = NULL,
                 `updated_at` = ?
             WHERE `id` = ?"
        );
        $now = date('Y-m-d H:i:s');

        foreach (self::defaultTemplates() as $template) {
            $existingStmt = $pdo->prepare("SELECT `id` FROM `message_template` WHERE `code` = ? LIMIT 1");
            $existingStmt->execute([$template['code']]);
            $existingId = $existingStmt->fetchColumn();
            if ($existingId) {
                $restoreStmt->execute([$now, (int) $existingId]);
                continue;
            }

            $stmt->execute([
                $template['uuid'],
                $template['name'],
                $template['code'],
                $template['title_tpl'],
                $template['content_tpl'],
                $template['type'],
                $template['level'],
                $template['description'],
                self::jsonValue($template['variables']),
                $template['link_url_tpl'],
                self::jsonValue(['internal']),
                $template['sort'],
                $now,
                $now,
            ]);
        }
    }

    private static function ensureDefaultTemplatePdoSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `message_template` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `title_tpl` VARCHAR(255) DEFAULT NULL,
            `content_tpl` TEXT DEFAULT NULL,
            `type` VARCHAR(40) DEFAULT 'system',
            `level` VARCHAR(40) DEFAULT 'normal',
            `description` VARCHAR(500) DEFAULT NULL,
            `variables` JSON DEFAULT NULL,
            `link_url_tpl` VARCHAR(500) DEFAULT NULL,
            `channels` JSON DEFAULT NULL,
            `is_system` TINYINT(1) DEFAULT 0,
            `sort` INT DEFAULT 100,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            UNIQUE KEY `uk_code` (`code`),
            KEY `idx_status` (`status`),
            KEY `idx_type_status` (`type`, `status`, `sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columns = [
            'title_tpl' => "ALTER TABLE `message_template` ADD COLUMN `title_tpl` VARCHAR(255) DEFAULT NULL AFTER `code`",
            'content_tpl' => "ALTER TABLE `message_template` ADD COLUMN `content_tpl` TEXT DEFAULT NULL AFTER `title_tpl`",
            'type' => "ALTER TABLE `message_template` ADD COLUMN `type` VARCHAR(40) DEFAULT 'system' AFTER `content_tpl`",
            'level' => "ALTER TABLE `message_template` ADD COLUMN `level` VARCHAR(40) DEFAULT 'normal' AFTER `type`",
            'description' => "ALTER TABLE `message_template` ADD COLUMN `description` VARCHAR(500) DEFAULT NULL AFTER `level`",
            'variables' => "ALTER TABLE `message_template` ADD COLUMN `variables` JSON DEFAULT NULL AFTER `description`",
            'link_url_tpl' => "ALTER TABLE `message_template` ADD COLUMN `link_url_tpl` VARCHAR(500) DEFAULT NULL AFTER `variables`",
            'channels' => "ALTER TABLE `message_template` ADD COLUMN `channels` JSON DEFAULT NULL AFTER `link_url_tpl`",
            'is_system' => "ALTER TABLE `message_template` ADD COLUMN `is_system` TINYINT(1) DEFAULT 0 AFTER `channels`",
            'sort' => "ALTER TABLE `message_template` ADD COLUMN `sort` INT DEFAULT 100 AFTER `is_system`",
        ];
        foreach ($columns as $column => $ddl) {
            self::ensurePdoColumnExists($pdo, 'message_template', $column, $ddl);
        }

        self::ensurePdoIndexExists($pdo, 'message_template', 'uk_code', "ALTER TABLE `message_template` ADD UNIQUE KEY `uk_code` (`code`)");
        self::ensurePdoIndexExists($pdo, 'message_template', 'idx_type_status', "ALTER TABLE `message_template` ADD KEY `idx_type_status` (`type`, `status`, `sort`)");
    }

    private static function ensurePdoColumnExists(PDO $pdo, string $table, string $column, string $ddl): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec($ddl);
        }
    }

    private static function ensurePdoIndexExists(PDO $pdo, string $table, string $index, string $ddl): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec($ddl);
        }
    }

    private static function defaultTemplateRow(array $template, string $now): array
    {
        return [
            'uuid' => $template['uuid'],
            'name' => $template['name'],
            'code' => $template['code'],
            'title_tpl' => $template['title_tpl'],
            'content_tpl' => $template['content_tpl'],
            'type' => $template['type'],
            'level' => $template['level'],
            'description' => $template['description'],
            'variables' => self::jsonValue($template['variables']),
            'link_url_tpl' => $template['link_url_tpl'],
            'channels' => self::jsonValue(['internal']),
            'is_system' => 1,
            'sort' => $template['sort'],
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
    }

    private static function inboxQuery(int $accountId): mixed
    {
        return self::queryTable('message_target as mt')
            ->join('message as m', 'mt.message_id', '=', 'm.id')
            ->where('mt.account_id', $accountId)
            ->where('m.status', 'enabled')
            ->whereNull('m.deleted_at')
            ->whereNull('mt.deleted_at');
    }

    private static function applyInboxFilters(mixed $query, array $filters): mixed
    {
        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '' && $type !== 'all') {
            $query->where('m.type', self::normalizeType($type));
        }

        $readStatus = trim((string) ($filters['status'] ?? 'all'));
        if ($readStatus === 'unread') {
            $query->where('mt.is_read', 0);
        } elseif ($readStatus === 'read') {
            $query->where('mt.is_read', 1);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('m.title', 'like', $like)
                    ->orWhere('m.content', 'like', $like)
                    ->orWhere('m.sender_name', 'like', $like);
            });
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $query->where('m.created_at', '>=', $dateFrom . ' 00:00:00');
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $query->where('m.created_at', '<=', $dateTo . ' 23:59:59');
        }

        return $query;
    }

    private static function inboxColumns(): array
    {
        return [
            'mt.id as target_id',
            'mt.message_id',
            'mt.account_id',
            'mt.is_read',
            'mt.read_at',
            'm.title',
            'm.content',
            'm.type',
            'm.sender_id',
            'm.sender_name',
            'm.level',
            'm.entity_type',
            'm.entity_id',
            'm.link_url',
            'm.metadata',
            'm.created_at',
        ];
    }

    private static function messageRow(object $row): array
    {
        return [
            'target_id' => (int) $row->target_id,
            'message_id' => (int) $row->message_id,
            'account_id' => (int) $row->account_id,
            'title' => $row->title,
            'content' => $row->content,
            'type' => self::normalizeType((string) $row->type),
            'sender_id' => $row->sender_id === null ? null : (int) $row->sender_id,
            'sender_name' => $row->sender_name ?: '系统',
            'level' => self::normalizeLevel((string) $row->level),
            'entity_type' => $row->entity_type,
            'entity_id' => $row->entity_id === null ? null : (int) $row->entity_id,
            'link_url' => $row->link_url,
            'metadata' => self::decodeJson($row->metadata),
            'is_read' => (int) $row->is_read === 1,
            'read_at' => $row->read_at,
            'created_at' => $row->created_at,
            'date_key' => self::dateKey($row->created_at),
            'time_label' => self::timeLabel($row->created_at),
        ];
    }

    private static function dateKey(mixed $value): string
    {
        $text = (string) ($value ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $text) ? substr($text, 0, 10) : '';
    }

    private static function timeLabel(mixed $value): string
    {
        $text = (string) ($value ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $text) ? substr($text, 11, 5) : $text;
    }

    private static function createSchema(): void
    {
        $connection = self::connection();
        $connection->statement("CREATE TABLE IF NOT EXISTS `message` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `title` VARCHAR(180) DEFAULT NULL,
            `content` TEXT DEFAULT NULL,
            `type` VARCHAR(40) DEFAULT 'system',
            `sender_id` BIGINT UNSIGNED DEFAULT 0,
            `sender_name` VARCHAR(80) DEFAULT NULL,
            `level` VARCHAR(40) DEFAULT 'normal',
            `entity_type` VARCHAR(80) DEFAULT NULL,
            `entity_id` BIGINT UNSIGNED DEFAULT NULL,
            `link_url` VARCHAR(500) DEFAULT NULL,
            `metadata` JSON DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_status` (`status`),
            KEY `idx_type_created` (`type`, `created_at`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $connection->statement("CREATE TABLE IF NOT EXISTS `message_target` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `message_id` BIGINT UNSIGNED NOT NULL,
            `account_id` BIGINT UNSIGNED NOT NULL,
            `is_read` TINYINT(1) DEFAULT 0,
            `read_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            UNIQUE KEY `uk_message_account` (`message_id`, `account_id`),
            KEY `idx_account_read` (`account_id`, `is_read`, `created_at`),
            KEY `idx_message_id` (`message_id`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $connection->statement("CREATE TABLE IF NOT EXISTS `message_template` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'enabled',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `title_tpl` VARCHAR(255) DEFAULT NULL,
            `content_tpl` TEXT DEFAULT NULL,
            `type` VARCHAR(40) DEFAULT 'system',
            `level` VARCHAR(40) DEFAULT 'normal',
            `description` VARCHAR(500) DEFAULT NULL,
            `variables` JSON DEFAULT NULL,
            `link_url_tpl` VARCHAR(500) DEFAULT NULL,
            `channels` JSON DEFAULT NULL,
            `is_system` TINYINT(1) DEFAULT 0,
            `sort` INT DEFAULT 100,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            UNIQUE KEY `uk_code` (`code`),
            KEY `idx_status` (`status`),
            KEY `idx_type_status` (`type`, `status`, `sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $connection->statement("CREATE TABLE IF NOT EXISTS `message_channel_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `name` VARCHAR(180) DEFAULT NULL,
            `code` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(40) DEFAULT 'pending',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            `message_id` BIGINT UNSIGNED NOT NULL,
            `account_id` BIGINT UNSIGNED NOT NULL,
            `channel` VARCHAR(40) DEFAULT 'internal',
            `error_message` TEXT DEFAULT NULL,
            `sent_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_uuid` (`uuid`),
            KEY `idx_message_account` (`message_id`, `account_id`),
            KEY `idx_channel_status` (`channel`, `status`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        self::ensureColumns();
        self::ensureIndexes();
    }

    private static function ensureColumns(): void
    {
        $columns = [
            'message' => [
                'title' => "ALTER TABLE `message` ADD COLUMN `title` VARCHAR(180) DEFAULT NULL AFTER `code`",
                'content' => "ALTER TABLE `message` ADD COLUMN `content` TEXT DEFAULT NULL AFTER `title`",
                'type' => "ALTER TABLE `message` ADD COLUMN `type` VARCHAR(40) DEFAULT 'system' AFTER `content`",
                'sender_id' => "ALTER TABLE `message` ADD COLUMN `sender_id` BIGINT UNSIGNED DEFAULT 0 AFTER `type`",
                'sender_name' => "ALTER TABLE `message` ADD COLUMN `sender_name` VARCHAR(80) DEFAULT NULL AFTER `sender_id`",
                'level' => "ALTER TABLE `message` ADD COLUMN `level` VARCHAR(40) DEFAULT 'normal' AFTER `sender_name`",
                'entity_type' => "ALTER TABLE `message` ADD COLUMN `entity_type` VARCHAR(80) DEFAULT NULL AFTER `level`",
                'entity_id' => "ALTER TABLE `message` ADD COLUMN `entity_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_type`",
                'link_url' => "ALTER TABLE `message` ADD COLUMN `link_url` VARCHAR(500) DEFAULT NULL AFTER `entity_id`",
                'metadata' => "ALTER TABLE `message` ADD COLUMN `metadata` JSON DEFAULT NULL AFTER `link_url`",
            ],
            'message_target' => [
                'message_id' => "ALTER TABLE `message_target` ADD COLUMN `message_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
                'account_id' => "ALTER TABLE `message_target` ADD COLUMN `account_id` BIGINT UNSIGNED DEFAULT NULL AFTER `message_id`",
                'is_read' => "ALTER TABLE `message_target` ADD COLUMN `is_read` TINYINT(1) DEFAULT 0 AFTER `account_id`",
                'read_at' => "ALTER TABLE `message_target` ADD COLUMN `read_at` DATETIME DEFAULT NULL AFTER `is_read`",
            ],
            'message_template' => [
                'title_tpl' => "ALTER TABLE `message_template` ADD COLUMN `title_tpl` VARCHAR(255) DEFAULT NULL AFTER `code`",
                'content_tpl' => "ALTER TABLE `message_template` ADD COLUMN `content_tpl` TEXT DEFAULT NULL AFTER `title_tpl`",
                'channels' => "ALTER TABLE `message_template` ADD COLUMN `channels` JSON DEFAULT NULL AFTER `content_tpl`",
                'type' => "ALTER TABLE `message_template` ADD COLUMN `type` VARCHAR(40) DEFAULT 'system' AFTER `content_tpl`",
                'level' => "ALTER TABLE `message_template` ADD COLUMN `level` VARCHAR(40) DEFAULT 'normal' AFTER `type`",
                'description' => "ALTER TABLE `message_template` ADD COLUMN `description` VARCHAR(500) DEFAULT NULL AFTER `level`",
                'variables' => "ALTER TABLE `message_template` ADD COLUMN `variables` JSON DEFAULT NULL AFTER `description`",
                'link_url_tpl' => "ALTER TABLE `message_template` ADD COLUMN `link_url_tpl` VARCHAR(500) DEFAULT NULL AFTER `variables`",
                'is_system' => "ALTER TABLE `message_template` ADD COLUMN `is_system` TINYINT(1) DEFAULT 0 AFTER `channels`",
                'sort' => "ALTER TABLE `message_template` ADD COLUMN `sort` INT DEFAULT 100 AFTER `is_system`",
            ],
            'message_channel_log' => [
                'message_id' => "ALTER TABLE `message_channel_log` ADD COLUMN `message_id` BIGINT UNSIGNED DEFAULT NULL AFTER `code`",
                'account_id' => "ALTER TABLE `message_channel_log` ADD COLUMN `account_id` BIGINT UNSIGNED DEFAULT NULL AFTER `message_id`",
                'channel' => "ALTER TABLE `message_channel_log` ADD COLUMN `channel` VARCHAR(40) DEFAULT 'internal' AFTER `account_id`",
                'error_message' => "ALTER TABLE `message_channel_log` ADD COLUMN `error_message` TEXT DEFAULT NULL AFTER `status`",
                'sent_at' => "ALTER TABLE `message_channel_log` ADD COLUMN `sent_at` DATETIME DEFAULT NULL AFTER `error_message`",
            ],
        ];

        foreach ($columns as $table => $tableColumns) {
            foreach ($tableColumns as $column => $ddl) {
                self::ensureColumnExists($table, $column, $ddl);
            }
        }
    }

    private static function ensureIndexes(): void
    {
        $indexes = [
            ['message', 'idx_type_created', "ALTER TABLE `message` ADD KEY `idx_type_created` (`type`, `created_at`)"],
            ['message', 'idx_entity', "ALTER TABLE `message` ADD KEY `idx_entity` (`entity_type`, `entity_id`)"],
            ['message', 'idx_deleted_at', "ALTER TABLE `message` ADD KEY `idx_deleted_at` (`deleted_at`)"],
            ['message_target', 'idx_account_read', "ALTER TABLE `message_target` ADD KEY `idx_account_read` (`account_id`, `is_read`, `created_at`)"],
            ['message_target', 'idx_message_id', "ALTER TABLE `message_target` ADD KEY `idx_message_id` (`message_id`)"],
            ['message_target', 'idx_deleted_at', "ALTER TABLE `message_target` ADD KEY `idx_deleted_at` (`deleted_at`)"],
            ['message_template', 'uk_code', "ALTER TABLE `message_template` ADD UNIQUE KEY `uk_code` (`code`)"],
            ['message_template', 'idx_type_status', "ALTER TABLE `message_template` ADD KEY `idx_type_status` (`type`, `status`, `sort`)"],
            ['message_channel_log', 'idx_message_account', "ALTER TABLE `message_channel_log` ADD KEY `idx_message_account` (`message_id`, `account_id`)"],
            ['message_channel_log', 'idx_channel_status', "ALTER TABLE `message_channel_log` ADD KEY `idx_channel_status` (`channel`, `status`)"],
            ['message_channel_log', 'idx_deleted_at', "ALTER TABLE `message_channel_log` ADD KEY `idx_deleted_at` (`deleted_at`)"],
        ];

        foreach ($indexes as [$table, $index, $ddl]) {
            self::ensureIndexExists($table, $index, $ddl);
        }
    }

    private static function ensureColumnExists(string $table, string $column, string $ddl): void
    {
        $row = self::connection()->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        if ((int) ($row->total ?? 0) === 0) {
            self::connection()->statement($ddl);
        }
    }

    private static function ensureIndexExists(string $table, string $index, string $ddl): void
    {
        $row = self::connection()->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        );
        if ((int) ($row->total ?? 0) === 0) {
            self::connection()->statement($ddl);
        }
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public static function defaultTemplates(): array
    {
        return [
            [
                'uuid' => '00000000-0000-0000-0000-000000240001',
                'name' => '流程提交待办',
                'code' => 'workflow_submit_todo',
                'title_tpl' => '待审核：{module_name}',
                'content_tpl' => '{submitter_name}提交了{module_name}，业务对象：{entity_title}。请及时处理。',
                'type' => 'todo',
                'level' => 'important',
                'description' => '学生或教师提交业务后，发送给审核人形成待办。',
                'variables' => [
                    'module_name' => '业务名称，如实习日志、实习报告',
                    'submitter_name' => '提交人姓名',
                    'entity_title' => '业务标题或学生姓名',
                ],
                'link_url_tpl' => '#panel={module_key}:{panel_key}',
                'sort' => 10,
            ],
            [
                'uuid' => '00000000-0000-0000-0000-000000240002',
                'name' => '审核结果通知',
                'code' => 'workflow_review_result',
                'title_tpl' => '{module_name}审核结果：{status_text}',
                'content_tpl' => '{module_name}已处理，结果：{status_text}。{opinion_text}',
                'type' => 'result',
                'level' => 'important',
                'description' => '审核通过、退回、拒绝后发送给提交人。',
                'variables' => [
                    'module_name' => '业务名称',
                    'status_text' => '通过、退回修改、拒绝',
                    'opinion_text' => '审核意见文本',
                ],
                'link_url_tpl' => '#panel={module_key}:{panel_key}',
                'sort' => 20,
            ],
            [
                'uuid' => '00000000-0000-0000-0000-000000240003',
                'name' => '通过后修改待办',
                'code' => 'workflow_reopen_todo',
                'title_tpl' => '{module_name}需要重新修改',
                'content_tpl' => '{reviewer_name}要求你重新修改{module_name}，业务对象：{entity_title}。{opinion_text}',
                'type' => 'todo',
                'level' => 'urgent',
                'description' => '审核通过后发起修改时发送给提交人。',
                'variables' => [
                    'module_name' => '业务名称',
                    'reviewer_name' => '审核人姓名',
                    'entity_title' => '业务标题或学生姓名',
                    'opinion_text' => '修改理由',
                ],
                'link_url_tpl' => '#panel={module_key}:{panel_key}',
                'sort' => 30,
            ],
            [
                'uuid' => '00000000-0000-0000-0000-000000240004',
                'name' => '实习任务发布',
                'code' => 'internship_task_publish',
                'title_tpl' => '实习任务已发布：{task_title}',
                'content_tpl' => '你的实习任务「{task_title}」已发布。时间：{date_text}；地点：{location}。',
                'type' => 'todo',
                'level' => 'important',
                'description' => '实习任务发布或变更生效后通知老师和学生。',
                'variables' => [
                    'task_title' => '实习任务标题',
                    'date_text' => '任务起止时间',
                    'location' => '任务地点',
                ],
                'link_url_tpl' => '#panel=internship:arrangements',
                'sort' => 40,
            ],
            [
                'uuid' => '00000000-0000-0000-0000-000000240005',
                'name' => '系统通知',
                'code' => 'system_notice',
                'title_tpl' => '{notice_title}',
                'content_tpl' => '{notice_content}',
                'type' => 'system',
                'level' => 'normal',
                'description' => '后台主动发送的通用系统通知。',
                'variables' => [
                    'notice_title' => '通知标题',
                    'notice_content' => '通知内容',
                ],
                'link_url_tpl' => '',
                'sort' => 50,
            ],
            [
                'uuid' => '00000000-0000-0000-0000-000000240006',
                'name' => '通用待办通知',
                'code' => 'todo_notice',
                'title_tpl' => '{notice_title}',
                'content_tpl' => '{notice_content}',
                'type' => 'todo',
                'level' => 'important',
                'description' => '业务已形成标题和内容时使用的通用待办模板。',
                'variables' => [
                    'notice_title' => '待办标题',
                    'notice_content' => '待办内容',
                ],
                'link_url_tpl' => '{link_url}',
                'sort' => 60,
            ],
            [
                'uuid' => '00000000-0000-0000-0000-000000240007',
                'name' => '通用结果通知',
                'code' => 'result_notice',
                'title_tpl' => '{notice_title}',
                'content_tpl' => '{notice_content}',
                'type' => 'result',
                'level' => 'important',
                'description' => '业务已形成标题和内容时使用的通用结果模板。',
                'variables' => [
                    'notice_title' => '通知标题',
                    'notice_content' => '通知内容',
                ],
                'link_url_tpl' => '{link_url}',
                'sort' => 70,
            ],
            [
                'uuid' => '00000000-0000-0000-0000-000000240008',
                'name' => '导出任务结果',
                'code' => 'export_task_result',
                'title_tpl' => '{export_title}',
                'content_tpl' => '{export_content}',
                'type' => 'result',
                'level' => 'normal',
                'description' => '导出任务完成或失败后通知发起人。',
                'variables' => [
                    'export_title' => '导出消息标题',
                    'export_content' => '导出消息内容',
                ],
                'link_url_tpl' => '#panel=exportTask:list',
                'sort' => 80,
            ],
            [
                'uuid' => '00000000-0000-0000-0000-000000240009',
                'name' => '实习每周简报通知',
                'code' => 'internship_weekly_brief',
                'title_tpl' => '{brief_title}',
                'content_tpl' => '{date_text}实习简报已生成。{summary_text}',
                'type' => 'system',
                'level' => 'normal',
                'description' => '每周实习简报生成后通知学校、学院和专业管理员。',
                'variables' => [
                    'brief_title' => '简报标题',
                    'date_text' => '统计周期',
                    'summary_text' => '简报摘要',
                ],
                'link_url_tpl' => '#panel=internship:overview',
                'sort' => 90,
            ],
            [
                'uuid' => '00000000-0000-0000-0000-000000240010',
                'name' => '实习保险到期提醒',
                'code' => 'internship_insurance_expiry',
                'title_tpl' => '实习保险即将到期：{student_name}',
                'content_tpl' => '{student_name}的实习保险将于{end_date}到期，保单号：{policy_number}，请及时续保。',
                'type' => 'todo',
                'level' => 'urgent',
                'description' => '保险到期前 7 天提醒学生、任务老师及对应学院和专业管理员。',
                'variables' => [
                    'student_name' => '学生姓名',
                    'end_date' => '保险到期日',
                    'policy_number' => '保单号',
                ],
                'link_url_tpl' => '#panel=internship:insurances',
                'sort' => 100,
            ],
            ...self::workflowTemplates(),
        ];
    }

    private static function workflowTemplates(): array
    {
        $definitions = [
            ['seq' => 101, 'prefix' => 'internship_plan', 'name' => '实习计划', 'panel' => '#panel=internship:plans'],
            ['seq' => 102, 'prefix' => 'internship_arrangement', 'name' => '实习任务', 'panel' => '#panel=internship:arrangements'],
            ['seq' => 104, 'prefix' => 'internship_arrangement_change', 'name' => '实习任务变更', 'panel' => '#panel=internship:arrangementChanges'],
            ['seq' => 107, 'prefix' => 'internship_application', 'name' => '实习方式申请', 'panel' => '#panel=internship:applications'],
            ['seq' => 110, 'prefix' => 'internship_journal', 'name' => '实习日志', 'panel' => '#panel=internship:journals'],
            ['seq' => 113, 'prefix' => 'internship_report', 'name' => '实习报告', 'panel' => '#panel=internship:reports'],
            ['seq' => 116, 'prefix' => 'internship_delay', 'name' => '延期申请', 'panel' => '#panel=internship:delays'],
            ['seq' => 119, 'prefix' => 'internship_insurance', 'name' => '保险记录', 'panel' => '#panel=internship:insurances'],
            ['seq' => 122, 'prefix' => 'internship_safety_letter', 'name' => '安全承诺', 'panel' => '#panel=internship:safetyLetters'],
            ['seq' => 125, 'prefix' => 'internship_syllabus_guide', 'name' => '实习大纲指导书', 'panel' => '#panel=internship:syllabusGuides'],
            ['seq' => 128, 'prefix' => 'internship_implementation_sheet', 'name' => '教学实习实施表', 'panel' => '#panel=internship:implementationSheets'],
            ['seq' => 131, 'prefix' => 'internship_teacher_work_report', 'name' => '指导教师工作报告', 'panel' => '#panel=internship:teacherWorkReports'],
            ['seq' => 134, 'prefix' => 'internship_inspection', 'name' => '实习巡查记录', 'panel' => '#panel=internship:inspections'],
            ['seq' => 137, 'prefix' => 'internship_base_application', 'name' => '基地申报', 'panel' => '#panel=internship:baseFlows'],
            ['seq' => 140, 'prefix' => 'internship_base_usage', 'name' => '基地使用', 'panel' => '#panel=internship:baseFlows'],
            ['seq' => 143, 'prefix' => 'internship_base_result', 'name' => '基地成果', 'panel' => '#panel=internship:baseFlows'],
            ['seq' => 146, 'prefix' => 'internship_base_expense', 'name' => '基地费用', 'panel' => '#panel=internship:baseFlows'],
            ['seq' => 149, 'prefix' => 'internship_graduation_appraisal', 'name' => '毕业实习成绩鉴定表', 'panel' => '#panel=internship:documents'],
            ['seq' => 152, 'prefix' => 'internship_student_change', 'name' => '学生实习资料变更', 'panel' => '#panel=internship:studentChanges'],
            ['seq' => 201, 'prefix' => 'training_plan', 'name' => '实训教学计划', 'panel' => '#panel=practice:plans'],
            ['seq' => 204, 'prefix' => 'training_syllabus', 'name' => '实训大纲', 'panel' => '#panel=practice:syllabus'],
            ['seq' => 207, 'prefix' => 'training_lesson_plan', 'name' => '实训教案', 'panel' => '#panel=practice:lessonPlans'],
            ['seq' => 210, 'prefix' => 'training_reflection', 'name' => '实训课程教学反思', 'panel' => '#panel=practice:reflections'],
            ['seq' => 213, 'prefix' => 'training_journal', 'name' => '实训历史过程记录', 'panel' => '#panel=practice:journals'],
            ['seq' => 216, 'prefix' => 'training_report', 'name' => '实训项目报告', 'panel' => '#panel=practice:reports'],
            ['seq' => 219, 'prefix' => 'training_score', 'name' => '实训成绩', 'panel' => '#panel=practice:scores'],
            ['seq' => 301, 'prefix' => 'lab_plan', 'name' => '实验教学计划', 'panel' => '#panel=practice:plans'],
            ['seq' => 304, 'prefix' => 'lab_syllabus', 'name' => '实验大纲', 'panel' => '#panel=practice:syllabus'],
            ['seq' => 307, 'prefix' => 'lab_lesson_plan', 'name' => '实验教案', 'panel' => '#panel=practice:lessonPlans'],
            ['seq' => 310, 'prefix' => 'lab_reflection', 'name' => '实验课程教学反思', 'panel' => '#panel=practice:reflections'],
            ['seq' => 313, 'prefix' => 'lab_journal', 'name' => '实验历史过程记录', 'panel' => '#panel=practice:journals'],
            ['seq' => 316, 'prefix' => 'lab_report', 'name' => '实验项目报告', 'panel' => '#panel=practice:reports'],
            ['seq' => 319, 'prefix' => 'lab_score', 'name' => '实验成绩', 'panel' => '#panel=practice:scores'],
            ['seq' => 401, 'prefix' => 'social_practice_plan', 'name' => '社会实践计划', 'panel' => '#panel=socialPractice:plans'],
            ['seq' => 404, 'prefix' => 'social_practice_project', 'name' => '社会实践项目', 'panel' => '#panel=socialPractice:centralized'],
            ['seq' => 407, 'prefix' => 'social_practice_implementation', 'name' => '集中实践实施申请', 'panel' => '#panel=socialPractice:centralized'],
            ['seq' => 410, 'prefix' => 'social_practice_declaration', 'name' => '分散实践申报', 'panel' => '#panel=socialPractice:distributed'],
            ['seq' => 413, 'prefix' => 'social_practice_material', 'name' => '社会实践材料', 'panel' => '#panel=socialPractice:materials'],
            ['seq' => 416, 'prefix' => 'social_practice_patch_sign', 'name' => '社会实践补签', 'panel' => '#panel=socialPractice:attendance'],
            ['seq' => 419, 'prefix' => 'social_practice_score', 'name' => '社会实践成绩', 'panel' => '#panel=socialPractice:scores'],
        ];

        $templates = [
            self::resultTemplate(190, '实习成绩核定结果', 'internship_score_result', '实习成绩已核定：{entity_title}', '你的实习成绩已核定，{score_text}。{opinion_text}', '#panel=internship:scores', 190),
            self::systemTemplate(430, '社会实践计划发布', 'social_practice_plan_published', '社会实践计划已发布：{entity_title}', '{entity_title}已发布，实践时间：{date_text}。', 'todo', 'important', '社会实践计划发布后通知适用学生和相关教师。', [
                'entity_title' => '计划标题',
                'date_text' => '实践起止时间',
            ], '#panel=socialPractice:plans', 430),
            self::systemTemplate(431, '社会实践教师确认提醒', 'social_practice_teacher_confirm_pending', '待确认社会实践申报', '{student_name}选择你指导社会实践项目「{entity_title}」，请在{deadline_text}前处理。', 'todo', 'important', '分散实践申报提交后通知指导教师。', [
                'student_name' => '学生或团队负责人',
                'entity_title' => '申报标题',
                'deadline_text' => '教师确认截止时间',
            ], '#panel=socialPractice:distributed', 431),
            self::systemTemplate(432, '社会实践安全材料提醒', 'social_practice_safety_incomplete', '社会实践安全材料待完善', '计划「{entity_title}」仍有必交安全材料未完成：{missing_text}。', 'todo', 'urgent', '实践开始前提醒学生和管理人员完善安全条件。', [
                'entity_title' => '计划或项目标题',
                'missing_text' => '缺失材料',
            ], '#panel=socialPractice:safety', 432),
            self::systemTemplate(433, '社会实践成果截止提醒', 'social_practice_result_due', '社会实践成果即将截止', '计划「{entity_title}」的成果材料将于{deadline_text}截止，请及时提交。', 'todo', 'important', '成果截止前通知未完成学生。', [
                'entity_title' => '计划标题',
                'deadline_text' => '成果截止时间',
            ], '#panel=socialPractice:materials', 433),
            self::systemTemplate(434, '社会实践归档通知', 'social_practice_archived', '社会实践已归档：{entity_title}', '社会实践「{entity_title}」已完成归档，归档版本：{version_text}。', 'result', 'normal', '社会实践归档完成后通知相关人员。', [
                'entity_title' => '归档对象',
                'version_text' => '归档版本',
            ], '#panel=socialPractice:archives', 434),
        ];
        foreach ($definitions as $definition) {
            $templates[] = self::submitTemplate($definition['seq'], $definition['name'], $definition['prefix'], $definition['panel']);
            $templates[] = self::reviewTemplate($definition['seq'] + 1, $definition['name'], $definition['prefix'], $definition['panel']);
            $templates[] = self::reopenTemplate($definition['seq'] + 2, $definition['name'], $definition['prefix'], $definition['panel']);
        }

        return $templates;
    }

    private static function submitTemplate(int $sequence, string $name, string $prefix, string $link): array
    {
        return self::systemTemplate($sequence, "{$name}提交待办", "{$prefix}_submit_todo", "待审核：{$name}", "{submitter_name}提交了{$name}「{entity_title}」，请及时审核。", 'todo', 'important', "{$name}提交后发送给审核人的待办模板。", [
            'submitter_name' => '提交人姓名',
            'entity_title' => '业务标题',
        ], $link, $sequence);
    }

    private static function reviewTemplate(int $sequence, string $name, string $prefix, string $link): array
    {
        return self::systemTemplate($sequence, "{$name}审核结果", "{$prefix}_review_result", "{$name}审核结果：{status_text}", "你的{$name}「{entity_title}」审核结果为{status_text}。{opinion_text}", 'result', 'important', "{$name}审核处理后发送给提交人的结果模板。", [
            'entity_title' => '业务标题',
            'status_text' => '通过、退回修改、未通过',
            'opinion_text' => '审核意见',
        ], $link, $sequence);
    }

    private static function reopenTemplate(int $sequence, string $name, string $prefix, string $link): array
    {
        return self::systemTemplate($sequence, "{$name}通过后修改待办", "{$prefix}_reopen_todo", "{$name}需要重新修改", "{reviewer_name}要求你重新修改{$name}「{entity_title}」。{opinion_text}", 'todo', 'urgent', "{$name}通过后发起修改时发送给提交人的待办模板。", [
            'reviewer_name' => '审核人姓名',
            'entity_title' => '业务标题',
            'opinion_text' => '修改理由',
        ], $link, $sequence);
    }

    private static function resultTemplate(int $sequence, string $name, string $code, string $title, string $content, string $link, int $sort): array
    {
        return self::systemTemplate($sequence, $name, $code, $title, $content, 'result', 'important', "{$name}发送给学生的结果模板。", [
            'entity_title' => '业务标题',
            'score_text' => '成绩说明',
            'opinion_text' => '补充说明',
        ], $link, $sort);
    }

    private static function systemTemplate(int $sequence, string $name, string $code, string $title, string $content, string $type, string $level, string $description, array $variables, string $link, int $sort): array
    {
        return [
            'uuid' => sprintf('00000000-0000-0000-0000-%012d', 240000 + $sequence),
            'name' => $name,
            'code' => $code,
            'title_tpl' => $title,
            'content_tpl' => $content,
            'type' => $type,
            'level' => $level,
            'description' => $description,
            'variables' => $variables,
            'link_url_tpl' => $link,
            'sort' => $sort,
        ];
    }

    private static function templateQuery(array $filters): mixed
    {
        $query = self::queryTable('message_template')->whereNull('deleted_at');
        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '' && $type !== 'all') {
            $query->where('type', self::normalizeType($type));
        }

        $status = trim((string) ($filters['status'] ?? 'all'));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status === 'disabled' ? 'disabled' : 'enabled');
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('title_tpl', 'like', $like)
                    ->orWhere('content_tpl', 'like', $like);
            });
        }

        return $query;
    }

    private static function templateColumns(): array
    {
        return [
            'id',
            'name',
            'code',
            'title_tpl',
            'content_tpl',
            'type',
            'level',
            'description',
            'variables',
            'link_url_tpl',
            'channels',
            'is_system',
            'sort',
            'status',
            'created_at',
            'updated_at',
        ];
    }

    private static function templateRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'code' => $row->code,
            'title_tpl' => $row->title_tpl,
            'content_tpl' => $row->content_tpl,
            'type' => self::normalizeType((string) ($row->type ?? 'system')),
            'level' => self::normalizeLevel((string) ($row->level ?? 'normal')),
            'description' => $row->description,
            'variables' => self::decodeJson($row->variables),
            'link_url_tpl' => $row->link_url_tpl,
            'channels' => self::decodeJson($row->channels),
            'is_system' => (int) ($row->is_system ?? 0) === 1,
            'sort' => (int) ($row->sort ?? 100),
            'status' => $row->status,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private static function nullableString(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    private static function jsonValue(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : ['text' => $value];
        }

        return json_encode($value ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decodeJson(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value ?: [];
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }

    private static function uuidValue(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

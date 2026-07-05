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

        return (int) $query->update([
            'is_read' => 1,
            'read_at' => $now,
            'updated_at' => $now,
        ]);
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

            return $messageId;
        });
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

        self::createSchema();
        self::seedDefaultTemplates();
        self::$schemaReady[$key] = true;
    }

    public static function seedDefaultTemplates(bool $force = false): void
    {
        self::ensureDefaultTemplates(self::connection(), $force);
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
            ...self::workflowTemplates(),
        ];
    }

    private static function workflowTemplates(): array
    {
        $definitions = [
            ['seq' => 101, 'prefix' => 'internship_plan', 'name' => '实习计划', 'panel' => '#panel=internship:plans'],
            ['seq' => 104, 'prefix' => 'internship_arrangement_change', 'name' => '实习任务变更', 'panel' => '#panel=internship:arrangementChanges'],
            ['seq' => 107, 'prefix' => 'internship_application', 'name' => '特殊申请', 'panel' => '#panel=internship:applications'],
            ['seq' => 110, 'prefix' => 'internship_journal', 'name' => '实习日志', 'panel' => '#panel=internship:journals'],
            ['seq' => 113, 'prefix' => 'internship_report', 'name' => '实习报告', 'panel' => '#panel=internship:reports'],
            ['seq' => 116, 'prefix' => 'internship_delay', 'name' => '延期申请', 'panel' => '#panel=internship:delays'],
            ['seq' => 201, 'prefix' => 'training_plan', 'name' => '实训教学计划', 'panel' => '#panel=training:plans'],
            ['seq' => 204, 'prefix' => 'training_syllabus', 'name' => '实训大纲', 'panel' => '#panel=training:syllabus'],
            ['seq' => 207, 'prefix' => 'training_lesson_plan', 'name' => '实训教案', 'panel' => '#panel=training:lessonPlans'],
            ['seq' => 210, 'prefix' => 'training_reflection', 'name' => '实训反思报告', 'panel' => '#panel=training:reflections'],
            ['seq' => 213, 'prefix' => 'training_journal', 'name' => '实训日志', 'panel' => '#panel=training:journals'],
            ['seq' => 216, 'prefix' => 'training_report', 'name' => '实训报告', 'panel' => '#panel=training:reports'],
            ['seq' => 301, 'prefix' => 'lab_plan', 'name' => '实验教学计划', 'panel' => '#panel=lab:plans'],
            ['seq' => 304, 'prefix' => 'lab_syllabus', 'name' => '实验大纲', 'panel' => '#panel=lab:syllabus'],
            ['seq' => 307, 'prefix' => 'lab_lesson_plan', 'name' => '实验教案', 'panel' => '#panel=lab:lessonPlans'],
            ['seq' => 310, 'prefix' => 'lab_reflection', 'name' => '实验反思报告', 'panel' => '#panel=lab:reflections'],
            ['seq' => 313, 'prefix' => 'lab_journal', 'name' => '实验日志', 'panel' => '#panel=lab:journals'],
            ['seq' => 316, 'prefix' => 'lab_report', 'name' => '实验报告', 'panel' => '#panel=lab:reports'],
        ];

        $templates = [
            self::resultTemplate(190, '实习成绩核定结果', 'internship_score_result', '实习成绩已核定：{entity_title}', '你的实习成绩已核定，{score_text}。{opinion_text}', '#panel=internship:scores', 190),
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

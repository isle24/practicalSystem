<?php

namespace app\model\channel;

class AuthPasskey extends BaseModel
{
    protected $table = 'auth_passkey';
    protected $primaryKey = 'id';
    protected $guarded = [];
    public $timestamps = false;

    private static array $schemaReady = [];

    public static function connection(): mixed
    {
        return (new static())->getConnection();
    }

    public static function reusable(int $creatorAccountId, int $targetAccountId, string $purpose, string $now): ?self
    {
        self::ensureTable();

        return self::query()
            ->where('creator_account_id', $creatorAccountId)
            ->where('target_account_id', $targetAccountId)
            ->where('purpose', $purpose)
            ->where('status', 'active')
            ->whereNull('used_at')
            ->whereNull('deleted_at')
            ->where('expires_at', '>', $now)
            ->orderByDesc('id')
            ->first();
    }

    public static function createKey(array $values): self
    {
        self::ensureTable();

        return self::query()->create(array_merge([
            'uuid' => self::uuid(),
            'purpose' => 'admin_login',
            'status' => 'active',
            'used_at' => null,
            'deleted_at' => null,
        ], $values));
    }

    public static function consume(string $passkey, string $now): ?array
    {
        self::ensureTable();

        return self::connection()->transaction(function () use ($now, $passkey): ?array {
            $record = self::query()
                ->where('passkey', $passkey)
                ->where('status', 'active')
                ->whereNull('used_at')
                ->whereNull('deleted_at')
                ->where('expires_at', '>', $now)
                ->lockForUpdate()
                ->first();

            if (!$record) {
                return null;
            }

            $record->status = 'used';
            $record->used_at = $now;
            $record->updated_at = $now;
            $record->save();

            return [
                'id' => (int) $record->id,
                'target_account_id' => (int) $record->target_account_id,
                'creator_account_id' => (int) $record->creator_account_id,
                'purpose' => (string) $record->purpose,
                'expires_at' => (string) $record->expires_at,
            ];
        });
    }

    public static function ensureTable(): void
    {
        $connection = self::connection();
        $key = $connection->getName();
        if (isset(self::$schemaReady[$key])) {
            return;
        }

        $connection->statement("CREATE TABLE IF NOT EXISTS `auth_passkey` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uuid` CHAR(36) DEFAULT NULL,
            `passkey` VARCHAR(160) NOT NULL,
            `purpose` VARCHAR(40) DEFAULT 'admin_login',
            `creator_account_id` BIGINT UNSIGNED NOT NULL,
            `target_account_id` BIGINT UNSIGNED NOT NULL,
            `creator_role_type` VARCHAR(40) DEFAULT NULL,
            `client` VARCHAR(20) DEFAULT 'WEB',
            `status` VARCHAR(20) DEFAULT 'active',
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME DEFAULT NULL,
            `updated_at` DATETIME DEFAULT NULL,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_passkey` (`passkey`),
            KEY `idx_creator_target` (`creator_account_id`, `target_account_id`, `purpose`, `status`, `expires_at`),
            KEY `idx_target` (`target_account_id`, `status`, `expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一次性登录凭证'");

        self::$schemaReady[$key] = true;
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

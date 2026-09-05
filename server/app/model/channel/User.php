<?php

namespace app\model\channel;

class User extends BaseModel
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function connection(): mixed
    {
        return (new static())->getConnection();
    }

    /** 锁定个人资料，序列化手机号绑定和设置保存。 */
    public static function lockProfile(int $id): ?self
    {
        return self::query()->where('id', $id)->where('status', 'enabled')->whereNull('deleted_at')->lockForUpdate()->first();
    }

    /** 保存短信验证通过的手机号。 */
    public static function bindVerifiedMobile(int $id, string $mobile, string $now): void
    {
        self::updateActiveProfile($id, ['mobile' => $mobile, 'verified_mobile' => $mobile, 'updated_at' => $now]);
    }

    public static function createAdminUser(array $values): self
    {
        return self::query()->create(array_merge([
            'uuid' => self::uuid(),
            'status' => 'enabled',
        ], $values));
    }

    public static function enabledById(int $id, array $columns = ['*']): ?self
    {
        return self::query()
            ->where('id', $id)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first($columns);
    }

    public static function activeById(int $id, array $columns = ['*']): ?self
    {
        return self::query()
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first($columns);
    }

    public static function updateActiveProfile(int $id, array $values): int
    {
        return self::query()
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->update($values);
    }

    public static function updateAdminUser(int $id, array $values): int
    {
        return self::query()
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->update($values);
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

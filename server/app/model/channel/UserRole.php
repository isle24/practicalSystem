<?php

namespace app\model\channel;

class UserRole extends BaseModel
{
    protected $table = 'user_role';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function setPrimaryRole(int $accountId, int $roleId, string $now): void
    {
        self::query()
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->update([
                'is_primary' => 'false',
                'updated_at' => $now,
            ]);

        $record = self::query()
            ->where('account_id', $accountId)
            ->where('role_id', $roleId)
            ->first();

        if ($record) {
            $record->is_primary = 'true';
            $record->deleted_at = null;
            $record->updated_at = $now;
            $record->save();
            return;
        }

        self::query()->create([
            'account_id' => $accountId,
            'role_id' => $roleId,
            'is_primary' => 'true',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

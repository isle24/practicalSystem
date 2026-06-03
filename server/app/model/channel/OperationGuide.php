<?php

namespace app\model\channel;

class OperationGuide extends BaseModel
{
    protected $table = 'operation_guide';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public static function enabledByModule(string $moduleKey): ?self
    {
        return self::query()
            ->where('module_key', $moduleKey)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->first(['id', 'module_key', 'title', 'content', 'status', 'sort', 'updated_at']);
    }

    public static function enabledItems(): array
    {
        return self::query()
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'module_key', 'title', 'content', 'status', 'sort', 'updated_at'])
            ->map(static fn ($item): array => $item->toArray())
            ->all();
    }

    public static function saveGuide(array $values): self
    {
        $guide = self::query()
            ->where('module_key', $values['module_key'])
            ->first();

        if ($guide) {
            $guide->fill($values);
            $guide->deleted_at = null;
            $guide->save();
            return $guide;
        }

        return self::query()->create($values);
    }

    public static function softDeleteById(int $id): bool
    {
        $guide = self::query()
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$guide) {
            return false;
        }

        $guide->status = 'disabled';
        $guide->deleted_at = date('Y-m-d H:i:s');
        return $guide->save();
    }
}

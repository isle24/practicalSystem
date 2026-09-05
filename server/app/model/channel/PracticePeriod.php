<?php

namespace app\model\channel;

class PracticePeriod extends TableRecord
{
    /** 获取课节选项 */
    public static function optionRows(bool $includeDisabled = false): array
    {
        $query = self::queryTable('practice_period')->whereNull('deleted_at');
        if (!$includeDisabled) {
            $query->where('status', 'enabled');
        }

        return self::rows($query->orderBy('sort')->orderBy('id')->get([
            'id', 'uuid', 'name', 'start_time', 'end_time', 'sort', 'status',
        ]));
    }

    /** 获取启用课节及历史课表引用的停用课节 */
    public static function scheduleRows(array $references = []): array
    {
        $pairs = [];
        $referencedIds = [];
        foreach ($references as $reference) {
            $startId = (int) (is_array($reference) ? ($reference['start_id'] ?? 0) : $reference);
            $endId = (int) (is_array($reference) ? ($reference['end_id'] ?? $startId) : $reference);
            if ($startId <= 0 || $endId <= 0) {
                continue;
            }
            $pairs[] = [$startId, $endId];
            $referencedIds[] = $startId;
            $referencedIds[] = $endId;
        }
        $referencedIds = array_values(array_unique($referencedIds));
        $sortById = [];
        if ($referencedIds) {
            foreach (self::queryTable('practice_period')->whereIn('id', $referencedIds)->whereNull('deleted_at')->get(['id', 'sort']) as $row) {
                $sortById[(int) $row->id] = (int) $row->sort;
            }
        }
        $sortRanges = [];
        foreach ($pairs as [$startId, $endId]) {
            if (!isset($sortById[$startId], $sortById[$endId])) {
                continue;
            }
            $sortRanges[] = [min($sortById[$startId], $sortById[$endId]), max($sortById[$startId], $sortById[$endId])];
        }

        $query = self::queryTable('practice_period')->whereNull('deleted_at');
        $query->where(function ($builder) use ($sortRanges): void {
            $builder->where('status', 'enabled');
            foreach ($sortRanges as [$startSort, $endSort]) {
                $builder->orWhereBetween('sort', [$startSort, $endSort]);
            }
        });

        return self::rows($query->orderBy('sort')->orderBy('id')->get([
            'id', 'uuid', 'name', 'start_time', 'end_time', 'sort', 'status',
        ]));
    }

    /** 分页查询课节 */
    public static function page(array $filters): array
    {
        $query = self::queryTable('practice_period')->whereNull('deleted_at');
        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['enabled', 'disabled'], true)) {
            $query->where('status', $status);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('name', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%');
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($filters['page_size'] ?? 20)));
        $total = (int) (clone $query)->count();

        return [
            'items' => self::rows($query->orderBy('sort')->orderBy('id')->forPage($page, $pageSize)->get()),
            'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total],
        ];
    }

    /** 获取启用的连续课节范围 */
    public static function activeRange(int $startId, int $endId): array
    {
        if ($startId <= 0 || $endId <= 0) {
            return [];
        }

        $rows = self::rows(self::queryTable('practice_period')
            ->whereIn('id', [$startId, $endId])
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'start_time', 'end_time', 'sort', 'status']));
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['id']] = $row;
        }
        if (!isset($indexed[$startId], $indexed[$endId])) {
            return [];
        }
        $startSort = (int) $indexed[$startId]['sort'];
        $endSort = (int) $indexed[$endId]['sort'];
        if ($endSort < $startSort) {
            return [];
        }

        $range = self::rows(self::queryTable('practice_period')
            ->whereBetween('sort', [$startSort, $endSort])
            ->whereNull('deleted_at')
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'name', 'start_time', 'end_time', 'sort', 'status']));
        if (!$range || count(array_filter($range, static fn (array $row): bool => ($row['status'] ?? '') !== 'enabled')) > 0) {
            return [];
        }

        return ['start' => $indexed[$startId], 'end' => $indexed[$endId], 'items' => $range];
    }

    /** 保存课节 */
    public static function saveRecord(?int $id, array $values): int
    {
        if ($id && $id > 0) {
            self::queryTable('practice_period')->where('id', $id)->whereNull('deleted_at')->update($values);
            return $id;
        }

        return (int) self::queryTable('practice_period')->insertGetId($values);
    }

    /** 将查询结果转换为数组 */
    private static function rows(iterable $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = method_exists($row, 'getAttributes') ? $row->getAttributes() : (array) $row;
        }

        return $items;
    }
}

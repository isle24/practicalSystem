<?php

namespace app\model\channel;

/** 实习归档材料要求配置查询。 */
class InternshipArchiveRequirementRecord extends TableRecord
{
    private const DEFAULTS = [
        'graduation' => [
            'plan', 'implementation_sheet', 'syllabus', 'guide', 'registration',
            'teacher_work_report', 'journal', 'graduation_report', 'graduation_appraisal',
            'score_register', 'safety_commitment',
        ],
        'internship' => [
            'plan', 'implementation_sheet', 'syllabus', 'guide', 'registration',
            'teacher_work_report', 'journal', 'report', 'score_register', 'safety_commitment',
        ],
    ];

    /** 查询指定实践类别的归档要求并补齐默认配置。 */
    public static function requirements(string $practiceType): array
    {
        self::ensureTable();
        $practiceType = self::normalizeType($practiceType);
        $storedRows = self::queryTable('internship_archive_requirement')
            ->where('practice_type', $practiceType)
            ->where('status', 'enabled')
            ->whereNull('deleted_at')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => $row->toArray())
            ->all();
        $storedByType = [];
        foreach ($storedRows as $row) {
            $storedByType[(string) ($row['material_type'] ?? '')] = $row;
        }

        $rows = array_map(static function (string $materialType, int $sort) use ($storedByType, $practiceType): array {
            return $storedByType[$materialType] ?? [
                'practice_type' => $practiceType,
                'material_type' => $materialType,
                'required' => 1,
                'sort' => $sort,
                'status' => 'enabled',
            ];
        }, self::DEFAULTS[$practiceType], array_keys(self::DEFAULTS[$practiceType]));

        foreach ($storedRows as $row) {
            if (!in_array((string) ($row['material_type'] ?? ''), self::DEFAULTS[$practiceType], true)) {
                $rows[] = $row;
            }
        }

        usort($rows, static fn (array $left, array $right): int => ((int) ($left['sort'] ?? 100) <=> (int) ($right['sort'] ?? 100))
            ?: ((int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0)));

        return $rows;
    }

    /** 将归档要求转换为材料类型索引。 */
    public static function requirementMap(string $practiceType): array
    {
        $map = [];
        foreach (self::requirements($practiceType) as $row) {
            $map[(string) $row['material_type']] = [
                'required' => (bool) $row['required'],
                'sort' => (int) $row['sort'],
            ];
        }
        return $map;
    }

    /** 保存一套归档材料要求。 */
    public static function saveRequirements(string $practiceType, array $items, int $operatorId, string $now): int
    {
        self::ensureTable();
        $practiceType = self::normalizeType($practiceType);
        return (int) self::connection()->transaction(function () use ($items, $now, $operatorId, $practiceType): int {
            $count = 0;
            $materialTypes = [];
            foreach ($items as $index => $item) {
                $materialType = trim((string) ($item['material_type'] ?? ''));
                if ($materialType === '') {
                    continue;
                }
                $materialTypes[] = $materialType;
                $existing = self::queryTable('internship_archive_requirement')
                    ->where('practice_type', $practiceType)
                    ->where('material_type', $materialType)
                    ->orderByRaw("status = 'enabled' DESC")
                    ->orderByDesc('id')
                    ->first(['id']);
                $values = [
                    'name' => '归档材料要求',
                    'practice_type' => $practiceType,
                    'material_type' => $materialType,
                    'required' => !empty($item['required']) ? 1 : 0,
                    'sort' => is_numeric($item['sort'] ?? null) ? (int) $item['sort'] : $index,
                    'created_by' => $operatorId,
                    'status' => 'enabled',
                    'updated_at' => $now,
                    'deleted_at' => null,
                ];
                if ($existing) {
                    self::queryTable('internship_archive_requirement')->where('id', (int) $existing->id)->update($values);
                } else {
                    self::queryTable('internship_archive_requirement')->insert(array_merge([
                        'uuid' => self::uuidValue(),
                        'created_at' => $now,
                    ], $values));
                }
                $count++;
            }

            $obsolete = self::queryTable('internship_archive_requirement')
                ->where('practice_type', $practiceType)
                ->where('status', 'enabled')
                ->whereNull('deleted_at');
            if ($materialTypes) {
                $obsolete->whereNotIn('material_type', array_values(array_unique($materialTypes)));
            }
            $obsolete->update(['required' => 0, 'updated_at' => $now]);

            return $count;
        });
    }

    /** 标准化归档配置类别。 */
    private static function normalizeType(string $practiceType): string
    {
        return trim($practiceType) === 'graduation' ? 'graduation' : 'internship';
    }

    /** 确保归档要求表存在。 */
    private static function ensureTable(): void
    {
        self::requireTables(['internship_archive_requirement']);
    }

    /** 生成 UUID。 */
    private static function uuidValue(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

<?php

namespace app\model\channel;

use RuntimeException;

class InternshipBaseImportRecord extends TableRecord
{
    /** 解析导入行关联的学院和专业 */
    public static function resolveAcademicScopes(array $rows, array $scope): array
    {
        $departmentQuery = self::queryTable('department')
            ->where('flag', 'on')
            ->whereNull('deleted_at');
        self::applyScope($departmentQuery, $scope, 'department.dep_id', null);
        $departments = $departmentQuery->get(['dep_id', 'dep_name']);
        $departmentMap = [];
        foreach ($departments as $department) {
            $departmentMap[self::nameKey((string) $department->dep_name)] = [
                'dep_id' => (int) $department->dep_id,
                'dep_name' => (string) $department->dep_name,
            ];
        }

        $professionQuery = self::queryTable('profession')
            ->where('flag', 'on')
            ->whereNull('deleted_at');
        self::applyScope($professionQuery, $scope, 'profession.dep_id', 'profession.profession_id');
        $professions = $professionQuery->get(['profession_id', 'profession_name', 'dep_id', 'grade_id']);
        $professionMap = [];
        foreach ($professions as $profession) {
            $professionMap[(int) $profession->dep_id][self::nameKey((string) $profession->profession_name)] = [
                'profession_id' => (int) $profession->profession_id,
                'profession_name' => (string) $profession->profession_name,
                'dep_id' => (int) $profession->dep_id,
                'grade_id' => (int) ($profession->grade_id ?? 0),
            ];
        }

        $resolvedRows = [];
        foreach ($rows as $row) {
            $errors = array_values(array_filter((array) ($row['errors'] ?? []), 'is_string'));
            $warnings = array_values(array_filter((array) ($row['warnings'] ?? []), 'is_string'));
            $department = $departmentMap[self::nameKey((string) ($row['dep_name'] ?? ''))] ?? null;
            if (!$department) {
                $errors[] = '学院不存在或不在当前账号管理范围内';
            }
            $professionIds = [];
            $professionNames = [];
            $missingProfessionNames = [];
            foreach ((array) ($row['profession_names'] ?? []) as $professionName) {
                $profession = $department
                    ? ($professionMap[$department['dep_id']][self::nameKey((string) $professionName)] ?? null)
                    : null;
                if (!$profession) {
                    $missingProfessionNames[] = (string) $professionName;
                    continue;
                }
                $professionIds[] = $profession['profession_id'];
                $professionNames[] = $profession['profession_name'];
            }
            if ($missingProfessionNames) {
                $warnings[] = '专业不存在或不属于所选学院，已跳过：' . implode('、', array_values(array_unique($missingProfessionNames)));
            }
            if (!$professionIds) {
                $errors[] = '服务专业没有可导入的有效专业';
            }

            $resolvedRows[] = array_merge($row, [
                'dep_id' => (int) ($department['dep_id'] ?? 0),
                'profession_ids' => array_values(array_unique($professionIds)),
                'resolved_profession_names' => array_values(array_unique($professionNames)),
                'errors' => array_values(array_unique($errors)),
                'warnings' => array_values(array_unique(array_merge((array) ($row['warnings'] ?? []), $warnings))),
            ]);
        }

        return $resolvedRows;
    }

    /** 为当前测试数据创建缺失学院和专业 */
    public static function createMissingAcademicScopes(array $rows, string $now): array
    {
        return self::connection()->transaction(function () use ($rows, $now): array {
            $gradeId = (int) (self::queryTable('grade_list')
                ->where('is_current', 'true')
                ->where('flag', 'on')
                ->whereNull('deleted_at')
                ->orderBy('sort')
                ->value('grade_id') ?: 0);
            if ($gradeId <= 0) {
                $gradeId = (int) (self::queryTable('grade_list')
                    ->where('flag', 'on')
                    ->whereNull('deleted_at')
                    ->orderBy('sort')
                    ->value('grade_id') ?: 0);
            }
            if ($gradeId <= 0) {
                throw new RuntimeException('请先维护当前年级');
            }

            $departmentNames = [];
            foreach ($rows as $row) {
                $name = trim((string) ($row['dep_name'] ?? ''));
                if ($name !== '') {
                    $departmentNames[self::nameKey($name)] = $name;
                }
            }
            asort($departmentNames, SORT_NATURAL);
            $departmentMap = self::departmentMap();
            $nextDepartmentCode = self::nextImportCode('department', 'dep_code', 'IMPORT_DEP_');
            $createdDepartments = 0;
            foreach ($departmentNames as $key => $name) {
                if (isset($departmentMap[$key])) {
                    continue;
                }
                $depId = (int) self::queryTable('department')->insertGetId([
                    'dep_uuid' => self::uuid(),
                    'dep_name' => $name,
                    'dep_short_name' => $name,
                    'dep_code' => sprintf('IMPORT_DEP_%03d', $nextDepartmentCode++),
                    'parent_id' => 0,
                    'sort' => 1000 + $createdDepartments,
                    'flag' => 'on',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ], 'dep_id');
                $departmentMap[$key] = ['dep_id' => $depId, 'dep_name' => $name];
                $createdDepartments++;
            }

            $professionMap = self::professionMap();
            $nextProfessionCode = self::nextImportCode('profession', 'profession_code', 'IMPORT_PRO_');
            $createdProfessions = 0;
            foreach ($rows as $row) {
                $department = $departmentMap[self::nameKey((string) ($row['dep_name'] ?? ''))] ?? null;
                if (!$department) {
                    continue;
                }
                $depId = (int) $department['dep_id'];
                foreach ((array) ($row['profession_names'] ?? []) as $professionName) {
                    $professionName = self::canonicalProfessionName((string) $professionName);
                    $key = self::nameKey($professionName);
                    if ($key === '' || isset($professionMap[$depId][$key])) {
                        continue;
                    }
                    $professionId = (int) self::queryTable('profession')->insertGetId([
                        'profession_uuid' => self::uuid(),
                        'profession_name' => $professionName,
                        'profession_short_name' => $professionName,
                        'profession_code' => sprintf('IMPORT_PRO_%03d', $nextProfessionCode++),
                        'dep_id' => $depId,
                        'grade_id' => $gradeId,
                        'sort' => 1000 + $createdProfessions,
                        'flag' => 'on',
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ], 'profession_id');
                    $professionMap[$depId][$key] = [
                        'profession_id' => $professionId,
                        'profession_name' => $professionName,
                    ];
                    $createdProfessions++;
                }
            }

            return [
                'grade_id' => $gradeId,
                'created_departments' => $createdDepartments,
                'created_professions' => $createdProfessions,
            ];
        });
    }

    /** 在事务中写入基地、年度申报及关联资料 */
    public static function importRows(array $rows, int $accountId, string $now): array
    {
        return self::connection()->transaction(function () use ($rows, $accountId, $now): array {
            $summary = [
                'total' => count($rows),
                'created_bases' => 0,
                'reused_bases' => 0,
                'created_declarations' => 0,
                'skipped_declarations' => 0,
                'created_companies' => 0,
                'created_profession_relations' => 0,
                'created_budgets' => 0,
                'created_reception_stats' => 0,
                'results' => [],
            ];

            foreach ($rows as $row) {
                $company = self::companyByName((string) $row['company_name'], true);
                if ($company) {
                    $companyId = (int) $company['company_id'];
                } else {
                    $companyId = (int) self::queryTable('companies')->insertGetId([
                        'company_uuid' => self::uuid(),
                        'company_name' => (string) $row['company_name'],
                        'contact_name' => $row['company_contact_name'] ?: null,
                        'contact_mobile' => $row['company_contact_phone'] ?: null,
                        'address' => $row['address'] ?: null,
                        'unit_type' => $row['unit_type'] ?: null,
                        'enterprise_level' => $row['enterprise_level'] ?: null,
                        'flag' => 'on',
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ], 'company_id');
                    $summary['created_companies']++;
                }

                $base = self::baseByIdentity((string) $row['base_name'], (int) $row['dep_id'], $companyId, true);
                if ($base) {
                    $baseId = (int) $base['id'];
                    $summary['reused_bases']++;
                } else {
                    $baseId = self::insertRow('base', [
                        'uuid' => self::uuid(),
                        'name' => (string) $row['base_name'],
                        'company_id' => $companyId,
                        'dep_id' => (int) $row['dep_id'],
                        'base_type' => 'long_term',
                        'address' => $row['address'] ?: null,
                        'district' => $row['district'] ?: null,
                        'annual_student_count' => $row['expected_student_visits'] ?? 0,
                        'current_student_count' => 0,
                        'service_courses' => $row['service_courses'] ?: null,
                        'manager_name' => $row['manager_name'] ?: null,
                        'manager_phone' => $row['manager_phone'] ?: null,
                        'capacity' => $row['expected_student_visits'] ?? 0,
                        'status' => 'enabled',
                        'created_by' => $accountId,
                        'updated_by' => $accountId,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ]);
                    self::insertManager($baseId, $row, $now);
                    $summary['created_bases']++;
                }

                foreach ((array) $row['profession_ids'] as $professionId) {
                    if (self::ensureBaseProfession($baseId, (int) $professionId, $now)) {
                        $summary['created_profession_relations']++;
                    }
                }

                $declaration = self::declarationByYear($baseId, (int) $row['declaration_year'], true);
                if ($declaration) {
                    $summary['skipped_declarations']++;
                    $summary['results'][] = [
                        'row_number' => (int) $row['row_number'],
                        'base_id' => $baseId,
                        'declaration_id' => (int) $declaration['id'],
                        'result' => 'skipped',
                        'message' => '同一基地申报年份已存在，未覆盖后台资料',
                    ];
                    continue;
                }

                $declarationId = self::insertRow('base_declaration', [
                    'uuid' => self::uuid(),
                    'base_id' => $baseId,
                    'declaration_year' => (int) $row['declaration_year'],
                    'base_category' => $row['base_category'] ?: null,
                    'base_level' => $row['base_level'] ?: null,
                    'project_status' => $row['project_status'] ?: null,
                    'approved_amount' => $row['approved_amount'],
                    'industry_cobuilt' => $row['industry_cobuilt'] ?: null,
                    'service_profession_count' => (int) ($row['service_profession_count'] ?? count($row['profession_ids'])),
                    'curriculum_in_plan' => $row['curriculum_in_plan'] ?: null,
                    'unit_type' => $row['unit_type'] ?: null,
                    'enterprise_level' => $row['enterprise_level'] ?: null,
                    'teacher_count' => (int) ($row['teacher_count'] ?? 0),
                    'external_teacher_count' => (int) ($row['external_teacher_count'] ?? 0),
                    'planned_content' => $row['planned_content'] ?: null,
                    'expected_student_visits' => (int) ($row['expected_student_visits'] ?? 0),
                    'expected_student_days' => $row['expected_student_days'],
                    'has_signboard' => $row['has_signboard'] ?: null,
                    'has_agreement' => $row['has_agreement'] ?: null,
                    'remark' => $row['remark'] ?: null,
                    'source_edited_at' => $row['source_edited_at'] ?: null,
                    'source_editor' => $row['source_editor'] ?: null,
                    'source_admin' => $row['source_admin'] ?: null,
                    'status' => 'enabled',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
                $summary['created_declarations']++;

                foreach ((array) ($row['budgets'] ?? []) as $sort => $budget) {
                    if (!is_array($budget) || ($budget['amount'] ?? null) === null) {
                        continue;
                    }
                    self::insertRow('base_budget', [
                        'uuid' => self::uuid(),
                        'base_id' => $baseId,
                        'declaration_id' => $declarationId,
                        'item_name' => (string) ($budget['item_name'] ?? ''),
                        'amount' => $budget['amount'],
                        'sort' => (int) $sort,
                        'status' => 'enabled',
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ]);
                    $summary['created_budgets']++;
                }

                foreach ((array) ($row['reception_stats'] ?? []) as $year => $studentCount) {
                    if ($studentCount === null) {
                        continue;
                    }
                    self::insertRow('base_reception_stat', [
                        'uuid' => self::uuid(),
                        'declaration_id' => $declarationId,
                        'stat_year' => (int) $year,
                        'student_count' => (int) $studentCount,
                        'status' => 'enabled',
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ]);
                    $summary['created_reception_stats']++;
                }

                $summary['results'][] = [
                    'row_number' => (int) $row['row_number'],
                    'base_id' => $baseId,
                    'declaration_id' => $declarationId,
                    'result' => 'created',
                    'message' => '基地申报资料已导入',
                ];
            }

            return $summary;
        });
    }

    /** 查询导入行是否已存在 */
    public static function duplicate(array $row): ?array
    {
        $base = self::baseByIdentity(
            (string) ($row['base_name'] ?? ''),
            (int) ($row['dep_id'] ?? 0),
            self::companyIdByName((string) ($row['company_name'] ?? '')),
            false
        );
        if (!$base) {
            return null;
        }
        $declaration = self::declarationByYear((int) $base['id'], (int) ($row['declaration_year'] ?? 0), false);

        return [
            'base_id' => (int) $base['id'],
            'declaration_id' => (int) ($declaration['id'] ?? 0),
            'base_exists' => true,
            'declaration_exists' => $declaration !== null,
        ];
    }

    /** 返回基地导入后的数量 */
    public static function importCounts(): array
    {
        return [
            'bases' => (int) self::queryTable('base')->whereNull('deleted_at')->count(),
            'declarations' => (int) self::queryTable('base_declaration')->whereNull('deleted_at')->count(),
            'profession_relations' => (int) self::queryTable('base_profession')->whereNull('deleted_at')->count(),
            'budgets' => (int) self::queryTable('base_budget')->whereNull('deleted_at')->count(),
            'reception_stats' => (int) self::queryTable('base_reception_stat')->whereNull('deleted_at')->count(),
        ];
    }

    /** 返回学院名称索引 */
    private static function departmentMap(): array
    {
        $map = [];
        foreach (self::queryTable('department')->whereNull('deleted_at')->get(['dep_id', 'dep_name']) as $row) {
            $map[self::nameKey((string) $row->dep_name)] = [
                'dep_id' => (int) $row->dep_id,
                'dep_name' => (string) $row->dep_name,
            ];
        }

        return $map;
    }

    /** 返回学院下的专业名称索引 */
    private static function professionMap(): array
    {
        $map = [];
        foreach (self::queryTable('profession')->whereNull('deleted_at')->get(['profession_id', 'profession_name', 'dep_id']) as $row) {
            $map[(int) $row->dep_id][self::nameKey((string) $row->profession_name)] = [
                'profession_id' => (int) $row->profession_id,
                'profession_name' => (string) $row->profession_name,
            ];
        }

        return $map;
    }

    /** 按单位名称查询合作单位编号 */
    private static function companyIdByName(string $companyName): int
    {
        if (trim($companyName) === '') {
            return 0;
        }

        return (int) (self::queryTable('companies')
            ->where('company_name', trim($companyName))
            ->whereNull('deleted_at')
            ->value('company_id') ?: 0);
    }

    /** 按单位名称查询合作单位 */
    private static function companyByName(string $companyName, bool $lock): ?array
    {
        $query = self::queryTable('companies')
            ->where('company_name', trim($companyName))
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first(['company_id', 'company_name']);

        return $row ? $row->getAttributes() : null;
    }

    /** 按名称、学院和单位查询基地 */
    private static function baseByIdentity(string $baseName, int $depId, int $companyId, bool $lock): ?array
    {
        if (trim($baseName) === '' || $depId <= 0 || $companyId <= 0) {
            return null;
        }
        $query = self::queryTable('base')
            ->where('name', trim($baseName))
            ->where('dep_id', $depId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first(['id', 'name']);

        return $row ? $row->getAttributes() : null;
    }

    /** 查询基地指定年份的申报资料 */
    private static function declarationByYear(int $baseId, int $year, bool $lock): ?array
    {
        if ($baseId <= 0 || $year <= 0) {
            return null;
        }
        $query = self::queryTable('base_declaration')
            ->where('base_id', $baseId)
            ->where('declaration_year', $year)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first(['id', 'base_id', 'declaration_year']);

        return $row ? $row->getAttributes() : null;
    }

    /** 恢复或创建基地专业关联 */
    private static function ensureBaseProfession(int $baseId, int $professionId, string $now): bool
    {
        $existing = self::queryTable('base_profession')
            ->where('base_id', $baseId)
            ->where('profession_id', $professionId)
            ->first(['id', 'deleted_at']);
        if ($existing) {
            if ($existing->deleted_at !== null) {
                self::updateById('base_profession', (int) $existing->id, [
                    'status' => 'enabled',
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            }
            return false;
        }

        self::insertRow('base_profession', [
            'uuid' => self::uuid(),
            'base_id' => $baseId,
            'profession_id' => $professionId,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
        return true;
    }

    /** 写入基地负责人资料 */
    private static function insertManager(int $baseId, array $row, string $now): void
    {
        if (trim((string) ($row['manager_name'] ?? '')) === '') {
            return;
        }
        self::insertRow('base_person', [
            'uuid' => self::uuid(),
            'base_id' => $baseId,
            'person_type' => 'manager',
            'name' => (string) $row['manager_name'],
            'phone' => $row['manager_phone'] ?: null,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    /** 应用学院或专业数据范围 */
    private static function applyScope(mixed $query, array $scope, string $depColumn, ?string $professionColumn): void
    {
        $roleType = (string) ($scope['role_type'] ?? '');
        if ($roleType === 'college_admin') {
            self::whereInOrDeny($query, $depColumn, $scope['dep_ids'] ?? []);
        } elseif ($roleType === 'profession_admin' && $professionColumn !== null) {
            self::whereInOrDeny($query, $professionColumn, $scope['profession_ids'] ?? []);
        } elseif ($roleType === 'profession_admin') {
            self::whereInOrDeny($query, $depColumn, $scope['dep_ids'] ?? []);
        }
    }

    /** 应用非空范围集合或拒绝全部数据 */
    private static function whereInOrDeny(mixed $query, string $column, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids) {
            $query->whereIn($column, $ids);
            return;
        }
        $query->whereRaw('1 = 0');
    }

    /** 写入记录并返回主键 */
    private static function insertRow(string $table, array $values): int
    {
        return (int) self::queryTable($table)->insertGetId($values);
    }

    /** 按主键更新记录 */
    private static function updateById(string $table, int $id, array $values): int
    {
        return self::queryTable($table)->where('id', $id)->update($values);
    }

    /** 计算导入临时代码的下一个序号 */
    private static function nextImportCode(string $table, string $column, string $prefix): int
    {
        $next = 1;
        foreach (self::queryTable($table)->where($column, 'like', $prefix . '%')->pluck($column) as $code) {
            if (preg_match('/(\d+)$/', (string) $code, $matches)) {
                $next = max($next, (int) $matches[1] + 1);
            }
        }

        return $next;
    }

    /** 规范化专业名称 */
    private static function canonicalProfessionName(string $name): string
    {
        $name = trim($name);
        return (string) preg_replace('/专业$/u', '', $name);
    }

    /** 生成名称匹配键 */
    private static function nameKey(string $name): string
    {
        $name = self::canonicalProfessionName($name);
        $name = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
        return (string) preg_replace('/[\s　]+/u', '', $name);
    }
}

<?php

namespace app\model\channel;

use InvalidArgumentException;

class ProfileAccountRecord extends TableRecord
{
    public static function provisionStudent(int $studentId, string $passwordHash): array
    {
        self::assertPasswordHash($passwordHash);
        return self::connection()->transaction(function () use ($studentId, $passwordHash): array {
            $profile = self::queryTable('students')->where('student_id', $studentId)->lockForUpdate()->first();
            self::assertProfile($profile, '学生');
            $number = self::requiredText($profile->student_num, '学号', 80);
            self::assertUniqueProfileNumber('students', 'student_num', $number);
            return self::provision('student', 'students', 'student_id', 'student_num', $profile, $number, $passwordHash);
        });
    }

    public static function provisionTeacher(array $values): array
    {
        $number = self::requiredText($values['teacher_num'] ?? null, '职工号', 80);
        $name = self::requiredText($values['teacher_name'] ?? null, '姓名', 80);
        $departmentName = self::requiredText($values['dep_name'] ?? null, '部门（学院）', 120);
        return self::connection()->transaction(function () use ($values, $number, $name, $departmentName): array {
            $departmentId = (int) ($values['dep_id'] ?? 0);
            if ($departmentId > 0) {
                $department = self::queryTable('department')->where('dep_id', $departmentId)->where('flag', 'on')
                    ->whereNull('deleted_at')->lockForUpdate()->first(['dep_id']);
                if (!$department) {
                    throw new InvalidArgumentException('匹配到的学院已停用或删除，请重新预览教师文件');
                }
            } else {
                $departments = self::queryTable('department')->where('flag', 'on')->whereNull('deleted_at')
                    ->lockForUpdate()->get(['dep_id', 'dep_name', 'dep_short_name']);
                $match = self::matchDepartment($departmentName, self::departmentAliasMap($departments->map(static fn ($row): array => (array) $row)->all()));
                if ($match['status'] !== 'matched') {
                    throw new InvalidArgumentException($match['message']);
                }
                $departmentId = (int) $match['department']['dep_id'];
            }
            self::assertUniqueProfileNumber('teacher_list', 'teacher_num', $number);
            $profile = self::queryTable('teacher_list')->where('teacher_num', $number)->lockForUpdate()->first();
            $changed = false;
            $now = date('Y-m-d H:i:s');
            if ($profile) {
                self::assertProfile($profile, '教师');
                $payload = ['teacher_name' => $name, 'dep_id' => $departmentId];
                foreach (['gender' => 20, 'birth_date' => 10, 'title' => 120, 'education' => 80, 'phone' => 40, 'email' => 120, 'employment_type' => 40] as $field => $limit) {
                    $value = self::optionalText($values[$field] ?? null, $field, $limit);
                    if ($value !== null) {
                        $payload[$field] = $value;
                    }
                }
                foreach ($payload as $field => $value) {
                    if ((string) ($profile->$field ?? '') !== (string) $value) {
                        $changed = true;
                    }
                }
            } else {
                $payload = [
                    'teacher_uuid' => self::uuid(), 'teacher_num' => $number, 'teacher_name' => $name,
                    'dep_id' => $departmentId, 'status' => 'enabled', 'sync_source' => 'teacher_excel',
                    'created_at' => $now, 'updated_at' => $now,
                ];
                foreach (['gender' => 20, 'birth_date' => 10, 'title' => 120, 'education' => 80, 'phone' => 40, 'email' => 120, 'employment_type' => 40] as $field => $limit) {
                    $payload[$field] = self::optionalText($values[$field] ?? null, $field, $limit);
                }
                $teacherId = (int) self::queryTable('teacher_list')->insertGetId($payload, 'teacher_id');
                $profile = self::queryTable('teacher_list')->where('teacher_id', $teacherId)->lockForUpdate()->first();
            }
            $accountProfile = clone $profile;
            foreach ($payload as $field => $value) {
                $accountProfile->$field = $value;
            }
            $result = self::provision('teacher', 'teacher_list', 'teacher_id', 'teacher_num', $accountProfile, $number, null);
            if ($changed && self::accountEnabled((int) $result['account_id'])) {
                self::queryTable('teacher_list')->where('teacher_id', (int) $profile->teacher_id)
                    ->update(array_merge($payload, ['updated_at' => $now, 'last_synced_at' => $now]));
                if ($result['action'] === 'skipped') {
                    $result['action'] = 'updated';
                }
            }
            return $result;
        });
    }

    public static function departmentAliasMap(array $departments): array
    {
        $map = ['exact' => [], 'alias' => []];
        foreach ($departments as $department) {
            $id = (int) ($department['dep_id'] ?? 0);
            $name = trim((string) ($department['dep_name'] ?? ''));
            if ($id < 1 || $name === '') {
                continue;
            }
            $department['dep_name'] = $name;
            $normalizedName = self::normalizeDepartmentName($name);
            $map['exact'][$normalizedName][$id] = $department;
            $aliases = [(string) ($department['dep_short_name'] ?? '')];
            if (str_ends_with($normalizedName, '学院')) {
                $aliases[] = mb_substr($normalizedName, 0, mb_strlen($normalizedName) - 2);
            }
            foreach ($aliases as $alias) {
                $key = self::normalizeDepartmentName($alias);
                if ($key !== '') {
                    $map['alias'][$key][$id] = $department;
                }
            }
        }
        return $map;
    }

    public static function matchDepartment(string $name, array $map): array
    {
        $key = self::normalizeDepartmentName($name);
        $matches = array_values($map['exact'][$key] ?? []);
        if (!$matches) {
            $matches = array_values($map['alias'][$key] ?? []);
        }
        if (count($matches) === 1) {
            return ['status' => 'matched', 'department' => $matches[0], 'message' => ''];
        }
        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'department' => null, 'message' => '学院简称对应多个学院，请填写学院全称或先维护唯一简称'];
        }
        return ['status' => 'not_found', 'department' => null, 'message' => '学院未匹配，请核对学院名称或在学院档案中维护简称'];
    }

    public static function normalizeDepartmentName(string $name): string
    {
        $name = trim($name);
        return (string) (preg_replace('/[\p{Z}\s\p{Cc}\p{Cf}]+/u', '', $name) ?? '');
    }

    private static function provision(string $roleType, string $table, string $idColumn, string $numberColumn, object $profile, string $number, ?string $passwordHash): array
    {
        $role = Account::registerRoleByType($roleType);
        if (!$role) {
            throw new InvalidArgumentException('对应角色不存在或未启用');
        }
        $name = self::requiredText($profile->{$roleType === 'teacher' ? 'teacher_name' : 'name'}, '姓名', 80);
        $userId = (int) ($profile->user_id ?? 0);
        $login = self::queryTable('account')->where('login_name', $number)->lockForUpdate()->first();
        if ($login && $login->deleted_at !== null) {
            throw new InvalidArgumentException('登录名被已删除账号占用，请人工处理');
        }
        if ($login && self::primaryRoleType((int) $login->id) !== $roleType) {
            throw new InvalidArgumentException('登录名已被其他角色账号占用');
        }
        if ($login && $userId > 0 && (int) $login->user_id !== $userId) {
            throw new InvalidArgumentException('登录名账号与档案所属用户不一致');
        }

        if ($userId > 0) {
            $user = self::queryTable('users')->where('id', $userId)->lockForUpdate()->first();
            if (!$user || $user->deleted_at !== null) {
                throw new InvalidArgumentException('档案关联用户不存在或已删除，请人工处理');
            }
            if ($login && trim((string) $user->name) !== $name) {
                throw new InvalidArgumentException('登录名已有账号的姓名与档案不一致');
            }
            self::assertUserProfile($table, $idColumn, $numberColumn, $profile, $userId, $number);
            $accounts = self::queryTable('account')->where('user_id', $userId)->whereNull('deleted_at')->lockForUpdate()->get();
            foreach ($accounts as $account) {
                if (self::primaryRoleType((int) $account->id) === $roleType) {
                    return ['action' => 'skipped', 'account_id' => (int) $account->id];
                }
            }
            if ($user->status !== 'enabled') {
                throw new InvalidArgumentException('档案关联用户已停用，不能创建账号');
            }
        } elseif ($login) {
            if ($login->status !== 'enabled') {
                throw new InvalidArgumentException('登录名被停用账号占用，请人工处理');
            }
            $user = self::queryTable('users')->where('id', (int) $login->user_id)->lockForUpdate()->first();
            if (!$user || $user->deleted_at !== null || $user->status !== 'enabled' || trim((string) $user->name) !== $name) {
                throw new InvalidArgumentException('登录名已有账号的用户状态或姓名与档案不一致');
            }
            $userId = (int) $user->id;
            self::assertUserProfile($table, $idColumn, $numberColumn, $profile, $userId, $number);
            self::queryTable($table)->where($idColumn, (int) $profile->$idColumn)
                ->update(['user_id' => $userId, 'updated_at' => date('Y-m-d H:i:s')]);
            return ['action' => 'linked', 'account_id' => (int) $login->id];
        }

        $now = date('Y-m-d H:i:s');
        if ($passwordHash === null) {
            if (strlen($number . '666') > 72) {
                throw new InvalidArgumentException('职工号过长，不能生成初始密码');
            }
            $passwordHash = password_hash($number . '666', PASSWORD_BCRYPT);
        }
        if ($userId < 1) {
            $user = User::createAdminUser([
                'name' => $name,
                'mobile' => $roleType === 'teacher' ? ($profile->phone ?? null) : null,
                'email' => $roleType === 'teacher' ? ($profile->email ?? null) : null,
                'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $userId = (int) $user->id;
        }
        $account = Account::createAdminAccount($userId, [
            'login_name' => $number, 'password' => $passwordHash, 'status' => 'enabled',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        UserRole::setPrimaryRole((int) $account->id, (int) $role['id'], $now);
        self::queryTable($table)->where($idColumn, (int) $profile->$idColumn)
            ->update(['user_id' => $userId, 'updated_at' => $now]);
        return ['action' => 'created', 'account_id' => (int) $account->id];
    }

    private static function assertUniqueProfileNumber(string $table, string $column, string $number): void
    {
        if (self::queryTable($table)->where($column, $number)->lockForUpdate()->limit(2)->get()->count() > 1) {
            throw new InvalidArgumentException('学号或工号存在重复档案，请人工处理');
        }
    }

    private static function assertProfile(?object $profile, string $label): void
    {
        if (!$profile || $profile->deleted_at !== null || $profile->status !== 'enabled') {
            throw new InvalidArgumentException($label . '档案不存在、已删除或已停用');
        }
    }

    private static function assertUserProfile(string $table, string $idColumn, string $numberColumn, object $profile, int $userId, string $number): void
    {
        $profiles = self::queryTable($table)->where('user_id', $userId)->lockForUpdate()->get([$idColumn, $numberColumn]);
        foreach ($profiles as $other) {
            if ((int) $other->$idColumn !== (int) $profile->$idColumn || trim((string) $other->$numberColumn) !== $number) {
                throw new InvalidArgumentException('账号所属用户已关联其他同类档案，请人工处理');
            }
        }
    }

    private static function primaryRoleType(int $accountId): ?string
    {
        $roles = self::queryTable('user_role')->join('role', 'role.id', '=', 'user_role.role_id')
            ->where('user_role.account_id', $accountId)->where('user_role.is_primary', 'true')
            ->whereNull('user_role.deleted_at')->whereNull('role.deleted_at')
            ->where('role.status', 'enabled')->lockForUpdate()->limit(2)->get(['role.role_type']);
        return $roles->count() === 1 ? (string) $roles->first()->role_type : null;
    }

    private static function accountEnabled(int $accountId): bool
    {
        return self::queryTable('account')->join('users', 'users.id', '=', 'account.user_id')
            ->where('account.id', $accountId)->where('account.status', 'enabled')->whereNull('account.deleted_at')
            ->where('users.status', 'enabled')->whereNull('users.deleted_at')->exists();
    }

    private static function assertPasswordHash(string $hash): void
    {
        if ((password_get_info($hash)['algoName'] ?? '') !== 'bcrypt') {
            throw new InvalidArgumentException('初始密码散列无效');
        }
    }

    private static function requiredText(mixed $value, string $label, int $limit): string
    {
        $text = self::optionalText($value, $label, $limit);
        if ($text === null) {
            throw new InvalidArgumentException($label . '不能为空');
        }
        return $text;
    }

    private static function optionalText(mixed $value, string $label, int $limit): ?string
    {
        if ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException($label . '格式无效');
        }
        $text = trim((string) $value);
        if (mb_strlen($text) > $limit || str_contains($text, "\0")) {
            throw new InvalidArgumentException($label . '长度或格式无效');
        }
        return $text === '' ? null : $text;
    }
}

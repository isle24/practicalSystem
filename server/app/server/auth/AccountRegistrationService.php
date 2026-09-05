<?php

namespace app\server\auth;

use app\model\channel\Account;
use app\model\channel\Role;
use app\model\channel\User;
use app\model\channel\UserRole;
use app\server\config\ConfigService;
use InvalidArgumentException;

class AccountRegistrationService
{
    private const PUBLIC_ROLE_TYPES = ['student', 'teacher'];
    private const PROFILE_ROLE_TYPES = ['student', 'teacher'];

    public function publicOptions(): array
    {
        return [
            'enabled' => $this->publicEnabled(),
            'roles' => $this->roleOptions(self::PUBLIC_ROLE_TYPES),
        ];
    }

    public function publicRegister(array $payload): array
    {
        if (!$this->publicEnabled()) {
            throw new InvalidArgumentException('注册入口已关闭');
        }

        return $this->create(array_merge($payload, [
            'role_type' => $this->publicRoleType((string) ($payload['role_type'] ?? 'student')),
            'status' => 'enabled',
        ]), self::PUBLIC_ROLE_TYPES);
    }

    public function adminSave(array $payload, ?int $accountId = null): array
    {
        $payload['id'] = $accountId;
        return $this->create($payload, []);
    }

    public function roleOptions(array $roleTypes = []): array
    {
        return Account::registerRoleOptions($roleTypes);
    }

    private function create(array $payload, array $allowedRoleTypes): array
    {
        $accountId = (int) ($payload['id'] ?? 0);
        $roleId = $this->roleId($payload);
        $role = Role::enabledById($roleId, ['id', 'role_type', 'name']);
        if (!$role) {
            throw new InvalidArgumentException('角色不存在或已停用');
        }
        $roleType = (string) $role->role_type;
        if ($allowedRoleTypes && !in_array($roleType, $allowedRoleTypes, true)) {
            throw new InvalidArgumentException('该角色暂不支持自助注册');
        }

        $loginName = $this->requiredText($payload, 'login_name', '登录账号', 80);
        if (Account::loginNameExists($loginName, $accountId ?: null)) {
            throw new InvalidArgumentException('登录账号已存在');
        }

        $name = $this->requiredText($payload, 'name', '姓名', 80);
        $password = $this->text($payload, 'password', 120);
        $initialPassword = null;
        if ($allowedRoleTypes && !$accountId && $password === '') {
            throw new InvalidArgumentException('密码不能为空');
        }
        if (!$allowedRoleTypes && !$accountId && $password === '') {
            $password = $this->randomInitialPassword();
            $initialPassword = $password;
        }
        if ($password !== '' && strlen($password) < 6) {
            throw new InvalidArgumentException('密码至少 6 位');
        }

        $status = in_array((string) ($payload['status'] ?? 'enabled'), ['enabled', 'disabled'], true)
            ? (string) ($payload['status'] ?? 'enabled')
            : 'enabled';
        $now = date('Y-m-d H:i:s');

        return Account::connection()->transaction(function () use ($accountId, $initialPassword, $loginName, $name, $now, $password, $payload, $roleId, $roleType, $status): array {
            $userValues = [
                'name' => $name,
                'mobile' => $this->nullableText($payload, 'mobile', 40),
                'email' => $this->nullableText($payload, 'email', 120),
                'status' => $status,
                'updated_at' => $now,
            ];
            $accountValues = [
                'login_name' => $loginName,
                'status' => $status,
                'updated_at' => $now,
            ];
            if ($password !== '') {
                $accountValues['password'] = password_hash($password, PASSWORD_BCRYPT);
            }

            if ($accountId > 0) {
                $account = Account::activeById($accountId, ['id', 'user_id']);
                if (!$account) {
                    throw new InvalidArgumentException('账号不存在');
                }
                User::updateAdminUser((int) $account->user_id, $userValues);
                Account::updateAdminAccount($accountId, $accountValues);
                UserRole::setPrimaryRole($accountId, $roleId, $now);
                $userId = (int) $account->user_id;
            } else {
                $user = User::createAdminUser(array_merge($userValues, ['created_at' => $now]));
                $account = Account::createAdminAccount((int) $user->id, array_merge($accountValues, ['created_at' => $now]));
                UserRole::setPrimaryRole((int) $account->id, $roleId, $now);
                $accountId = (int) $account->id;
                $userId = (int) $user->id;
            }

            if (in_array($roleType, self::PROFILE_ROLE_TYPES, true)) {
                Account::saveRoleProfile($roleType, $userId, $name, $status, $this->profileValues($payload, $roleType), $now);
            }

            $result = [
                'id' => $accountId,
                'user_id' => $userId,
                'role_id' => $roleId,
                'role_type' => $roleType,
            ];
            if ($initialPassword !== null) {
                $result['initial_password'] = $initialPassword;
            }

            return $result;
        });
    }

    private function randomInitialPassword(): string
    {
        return 'Ps' . bin2hex(random_bytes(5)) . random_int(10, 99);
    }

    private function publicEnabled(): bool
    {
        $value = (new ConfigService())->get('system.public_register_enabled');
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function publicRoleType(string $roleType): string
    {
        return in_array($roleType, self::PUBLIC_ROLE_TYPES, true) ? $roleType : 'student';
    }

    private function roleId(array $payload): int
    {
        $roleId = (int) ($payload['role_id'] ?? 0);
        if ($roleId > 0) {
            return $roleId;
        }

        $roleType = (string) ($payload['role_type'] ?? 'student');
        $role = Account::registerRoleByType($roleType);
        if (!$role) {
            throw new InvalidArgumentException('角色不存在或已停用');
        }

        return (int) $role['id'];
    }

    private function profileValues(array $payload, string $roleType): array
    {
        $values = [
            'dep_id' => $this->intValue($payload, 'dep_id'),
            'profession_id' => $this->intValue($payload, 'profession_id'),
        ];
        if ($roleType === 'student') {
            $values['student_num'] = $this->nullableText($payload, 'student_num', 80);
            $values['grade_id'] = $this->intValue($payload, 'grade_id');
            $values['graduation_cohort_id'] = $this->intValue($payload, 'graduation_cohort_id');
            $values['class_id'] = $this->intValue($payload, 'class_id');
            $values['class_num'] = $this->nullableText($payload, 'class_num', 80);
            return $values;
        }

        $values['teacher_num'] = $this->nullableText($payload, 'teacher_num', 80);
        return $values;
    }

    private function requiredText(array $payload, string $key, string $label, int $maxLength): string
    {
        $value = $this->text($payload, $key, $maxLength);
        if ($value === '') {
            throw new InvalidArgumentException($label . '不能为空');
        }

        return $value;
    }

    private function nullableText(array $payload, string $key, int $maxLength): ?string
    {
        $value = $this->text($payload, $key, $maxLength);
        return $value === '' ? null : $value;
    }

    private function text(array $payload, string $key, int $maxLength): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function intValue(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}

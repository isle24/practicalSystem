<?php

namespace app\server;

use support\Context;

class CurrentContext
{
    private const PREFIX = 'current.';

    public static function set(array $values): void
    {
        foreach ($values as $key => $value) {
            Context::set(self::PREFIX . $key, $value);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Context::get(self::PREFIX . $key, $default);
    }

    public static function all(): array
    {
        $keys = [
            'school_database_id',
            'school_database',
            'school_connection',
            'school_id',
            'school_code',
            'school_name',
            'user_id',
            'user_name',
            'account_id',
            'login_name',
            'role_id',
            'role_type',
            'role_name',
            'client',
            'permissions',
            'organization_scopes',
            'data_scope',
            'auth_error',
        ];

        $context = [];
        foreach ($keys as $key) {
            $context[$key] = self::get($key);
        }

        return $context;
    }

    public static function schoolDatabaseId(): ?int
    {
        return self::intValue('school_database_id');
    }

    public static function schoolDatabase(): ?string
    {
        $database = self::get('school_database');
        return $database === null || $database === '' ? null : (string) $database;
    }

    public static function schoolCode(): ?string
    {
        $code = self::get('school_code');
        return $code === null || $code === '' ? null : (string) $code;
    }

    public static function schoolConnection(): string
    {
        $connection = self::get('school_connection');
        return $connection === null || $connection === '' ? (string) config('database.default', 'mysql') : (string) $connection;
    }

    public static function userId(): ?int
    {
        return self::intValue('user_id');
    }

    public static function accountId(): ?int
    {
        return self::intValue('account_id');
    }

    public static function roleId(): ?int
    {
        return self::intValue('role_id');
    }

    public static function roleType(): ?string
    {
        $roleType = self::get('role_type');
        return $roleType === null || $roleType === '' ? null : (string) $roleType;
    }

    public static function permissionCodes(): array
    {
        return self::arrayValue('permissions');
    }

    public static function organizationScopes(): array
    {
        return self::arrayValue('organization_scopes');
    }

    public static function dataScope(): array
    {
        return self::arrayValue('data_scope');
    }

    private static function intValue(string $key): ?int
    {
        $value = self::get($key);
        return is_numeric($value) ? (int) $value : null;
    }

    private static function arrayValue(string $key): array
    {
        $value = self::get($key, []);
        return is_array($value) ? $value : [];
    }
}

<?php

namespace app\server\tenant;

use app\server\CurrentContext;
use Illuminate\Container\Container;
use InvalidArgumentException;
use ReflectionClass;
use support\Db;
use Throwable;

class TenantConnectionManager
{
    private const DEFAULT_MAX_SIZE = 16;

    private static array $active = [];

    public function ensureConnection(int $databaseId, array $config): string
    {
        if ($databaseId <= 0) {
            throw new InvalidArgumentException('database_id 无效');
        }

        $name = 'tenant_' . $databaseId;
        $connection = $this->buildConfig($config);
        $signature = $this->signature($connection, $config['config_version'] ?? $config['updated_at'] ?? null);
        $currentSignature = self::$active[$name]['signature'] ?? null;

        if ($currentSignature !== $signature) {
            $this->purge($name);
            $this->register($name, $connection);
        }

        self::$active[$name] = [
            'database_id' => $databaseId,
            'database' => $connection['database'],
            'signature' => $signature,
            'last_used_at' => time(),
        ];

        CurrentContext::set([
            'tenant_database_id' => $databaseId,
            'tenant_database' => $connection['database'],
            'tenant_connection' => $name,
            'school_id' => isset($config['school_id']) ? (int) $config['school_id'] : null,
            'school_code' => $config['school_code'] ?? null,
            'school_name' => $config['school_name'] ?? null,
        ]);

        $this->evict();

        return $name;
    }

    public function activeConnections(): array
    {
        return array_values(self::$active);
    }

    private function buildConfig(array $config): array
    {
        $template = (array) config('database.connections.tenant_template', config('database.connections.mysql', []));

        $template['driver'] = $template['driver'] ?? 'mysql';
        $template['host'] = $config['database_host'] ?? $config['host'] ?? $template['host'] ?? '127.0.0.1';
        $template['port'] = (int) ($config['database_port'] ?? $config['port'] ?? $template['port'] ?? 3306);
        $template['database'] = $config['database_db'] ?? $config['database'] ?? $template['database'] ?? '';
        $template['username'] = $config['database_user'] ?? $config['username'] ?? $template['username'] ?? 'root';
        $template['password'] = $config['database_pwd'] ?? $config['password'] ?? $template['password'] ?? '';
        $template['charset'] = $config['database_charset'] ?? $config['charset'] ?? $template['charset'] ?? 'utf8mb4';
        $template['prefix'] = $config['database_prefix'] ?? $config['prefix'] ?? $template['prefix'] ?? '';

        if ($template['database'] === '') {
            throw new InvalidArgumentException('database_db 不能为空');
        }

        return $template;
    }

    private function register(string $name, array $connection): void
    {
        $container = Container::getInstance();
        $connections = $container['config']['database.connections'] ?? [];
        $connections[$name] = $connection;
        $container['config']['database.connections'] = $connections;
    }

    private function purge(string $name): void
    {
        try {
            $reflection = new ReflectionClass(Db::class);
            $capsule = $reflection->getStaticPropertyValue('instance');
            $capsule?->getDatabaseManager()?->purge($name);
        } catch (Throwable) {
        }
    }

    private function evict(): void
    {
        $maxSize = (int) (getenv('TENANT_CONNECTION_MAX_SIZE') ?: self::DEFAULT_MAX_SIZE);
        $maxSize = max(3, $maxSize);

        if (count(self::$active) <= $maxSize) {
            return;
        }

        uasort(self::$active, static fn (array $left, array $right): int => $left['last_used_at'] <=> $right['last_used_at']);

        while (count(self::$active) > $maxSize) {
            $name = array_key_first(self::$active);
            if ($name === CurrentContext::tenantConnection()) {
                break;
            }
            $this->purge($name);
            unset(self::$active[$name]);
        }
    }

    private function signature(array $connection, mixed $version): string
    {
        return sha1(json_encode([
            'connection' => $connection,
            'version' => $version,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

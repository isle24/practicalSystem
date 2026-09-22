<?php

use app\server\database\SchemaMetadata;

require_once __DIR__ . '/init.php';

function schemaBuildEnvironment(): array
{
    $values = [];
    foreach (['HOST', 'PORT', 'USER', 'PASS'] as $key) {
        $value = getenv('SCHEMA_BUILD_' . $key);
        if ($value === false) {
            throw new InvalidArgumentException('必须显式设置 SCHEMA_BUILD_' . $key);
        }
        $values[strtolower($key)] = $value;
    }
    if (!in_array($values['host'], ['localhost', '127.0.0.1'], true)) {
        throw new InvalidArgumentException('结构基准只能在 localhost 或 127.0.0.1 构建');
    }
    if (!ctype_digit($values['port']) || (int) $values['port'] < 1 || (int) $values['port'] > 65535) {
        throw new InvalidArgumentException('SCHEMA_BUILD_PORT 无效');
    }
    if ($values['user'] === '') {
        throw new InvalidArgumentException('SCHEMA_BUILD_USER 不能为空');
    }
    return $values;
}

function schemaBaselineSourceHashes(): array
{
    $root = dirname(__DIR__);
    $paths = [
        'database/init.php',
        'database/build-schema-baseline.php',
        'app/server/database/SchemaMetadata.php',
        'app/server/edu/EduImportSchema.php',
        'app/model/channel/AssistantProfile.php',
        'app/model/channel/AuthPasskey.php',
        'app/model/channel/DataCleanupRecord.php',
        'app/model/channel/DesktopToolsSchema.php',
        'app/model/channel/DocRecord.php',
        'app/model/channel/ExportTaskRecord.php',
        'app/model/channel/InternshipScheduledRecord.php',
        'app/model/channel/MessageRealtimeRecord.php',
        'app/model/channel/MessageRecord.php',
        'app/model/channel/PluginRecord.php',
        'app/model/channel/RecordingArchiveRecord.php',
        'app/model/channel/TableRecord.php',
        'app/model/channel/TemplateRecord.php',
    ];
    foreach (['database/updates/*.sql', 'database/migrations/*.php'] as $pattern) {
        foreach (glob($root . '/' . $pattern) ?: [] as $path) {
            $paths[] = substr($path, strlen($root) + 1);
        }
    }
    $hashes = [];
    foreach (array_unique($paths) as $path) {
        $hash = hash_file('sha256', $root . '/' . $path);
        if ($hash === false) {
            throw new RuntimeException('无法读取结构来源：' . $path);
        }
        $hashes[$path] = $hash;
    }
    ksort($hashes);
    return $hashes;
}

function buildSchemaBaseline(): string
{
    $config = schemaBuildEnvironment();
    $sourceHashes = schemaBaselineSourceHashes();
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=' . (int) $config['port'] . ';charset=utf8mb4',
        $config['user'],
        $config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]
    );
    $version = '2026-09-22';
    $archiveYear = (int) substr($version, 0, 4);
    $prefix = 'practical_schema_build_' . bin2hex(random_bytes(12));
    $created = [];
    $schemas = [];
    try {
        foreach (['master', 'school'] as $scope) {
            $name = $prefix . '_' . $scope;
            $pdo->exec('CREATE DATABASE ' . quoteIdentifier($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            $created[] = $name;
            $pdo->exec('USE ' . quoteIdentifier($name));
            if ($scope === 'master') {
                createMasterSchema($pdo);
            } else {
                createSchoolSchema($pdo, $archiveYear);
            }
            $schemas[$scope] = SchemaMetadata::read($pdo);
        }
    } finally {
        $failed = [];
        foreach (array_reverse($created) as $name) {
            try {
                $pdo->exec('DROP DATABASE ' . quoteIdentifier($name));
            } catch (Throwable) {
                $failed[] = $name;
            }
        }
        if ($failed) {
            throw new RuntimeException('临时结构库清理失败：' . implode('、', $failed));
        }
    }

    $families = [];
    foreach ([
        '^operation_log_[0-9]{6}$' => 'operation_log_202606',
        '^recording_archive_[0-9]{4}$' => 'recording_archive_' . $archiveYear,
    ] as $pattern => $sourceName) {
        if (!isset($schemas['school'][$sourceName])) {
            throw new RuntimeException('缺少结构表族样本：' . $sourceName);
        }
        $families[] = ['pattern' => $pattern, 'template' => $schemas['school'][$sourceName], 'source_name' => $sourceName];
        foreach (array_keys($schemas['school']) as $name) {
            if (preg_match('/' . $pattern . '/D', $name)) {
                unset($schemas['school'][$name]);
            }
        }
    }
    if ($sourceHashes !== schemaBaselineSourceHashes()) {
        throw new RuntimeException('结构来源在构建期间发生变更，请重新生成');
    }
    $payload = [
        'version' => $version,
        'generated_at' => gmdate(DATE_ATOM),
        'source_hashes' => $sourceHashes,
        'schemas' => $schemas,
        'families' => ['school' => $families],
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $temporary = tempnam(__DIR__, '.schema-baseline-');
    if ($temporary === false) {
        throw new RuntimeException('无法创建结构基准文件');
    }
    $target = __DIR__ . '/schema-baseline.json';
    try {
        if (file_put_contents($temporary, $json) !== strlen($json) || !chmod($temporary, 0644) || !rename($temporary, $target)) {
            throw new RuntimeException('无法保存结构基准文件');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    return $target;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        buildSchemaBaseline();
        fwrite(STDOUT, "数据库结构基准已生成\n");
    } catch (Throwable $exception) {
        $password = getenv('SCHEMA_BUILD_PASS');
        fwrite(STDERR, '结构基准构建失败：' . sanitizeDatabaseError($exception->getMessage(), $password === false ? '' : $password) . "\n");
        exit(1);
    }
}

<?php

use app\server\edu\EduIdentityCipher;
use Dotenv\Dotenv;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$env = static fn (string $key, mixed $default = ''): mixed => $_ENV[$key] ?? (getenv($key) ?: $default);
$dryRun = !in_array('--apply', $argv, true);
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('DB_HOST', '127.0.0.1'), $env('DB_PORT', 3306), $env('DEFAULT_SCHOOL_DB', 'practical_default')),
    (string) $env('DB_USER', 'root'),
    (string) $env('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo $dryRun ? "== DRY RUN ==\n" : "== APPLY ==\n";
$total = (int) $pdo->query("SELECT COUNT(*) FROM edu_student_source WHERE email IS NOT NULL AND TRIM(email) <> ''")->fetchColumn();
printf("待清理学生邮箱明文：%d\n", $total);
if ($dryRun || $total === 0) {
    echo $dryRun ? "未指定 --apply，未做任何修改。\n" : "没有需要清理的数据。\n";
    exit(0);
}

$cipher = new EduIdentityCipher();
$select = $pdo->prepare('SELECT id, email, sensitive_payload_cipher, raw_payload FROM edu_student_source WHERE email IS NOT NULL AND TRIM(email) <> "" ORDER BY id LIMIT 500');
$update = $pdo->prepare('UPDATE edu_student_source SET email = NULL, sensitive_payload_cipher = ?, raw_payload = ?, updated_at = NOW() WHERE id = ? AND email IS NOT NULL');
$processed = 0;
while (true) {
    $select->execute();
    $rows = $select->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        break;
    }
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $payload = [];
            if ((string) ($row['sensitive_payload_cipher'] ?? '') !== '') {
                $payload = $cipher->decryptSensitive((string) $row['sensitive_payload_cipher']);
            }
            $payload['电子邮箱'] = (string) $row['email'];
            $raw = json_decode((string) ($row['raw_payload'] ?? ''), true);
            if (is_array($raw)) {
                unset($raw['电子邮箱'], $raw['email']);
            }
            $update->execute([
                $cipher->encryptSensitive($payload),
                json_encode(is_array($raw) ? $raw : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int) $row['id'],
            ]);
            $processed += $update->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

printf("已将邮箱写入加密负载并清空明文：%d\n", $processed);

$changeSelect = $pdo->query('SELECT id, before_json, after_json, diff_json FROM edu_import_change');
$changeUpdate = $pdo->prepare('UPDATE edu_import_change SET before_json = ?, after_json = ?, diff_json = ?, updated_at = NOW() WHERE id = ?');
$changes = 0;
while ($row = $changeSelect->fetch(PDO::FETCH_ASSOC)) {
    $values = [];
    foreach (['before_json', 'after_json', 'diff_json'] as $field) {
        $value = json_decode((string) ($row[$field] ?? ''), true);
        if (is_array($value)) {
            unset($value['email'], $value['电子邮箱']);
        }
        $values[] = json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($changeUpdate->execute([...$values, (int) $row['id']])) {
        $changes += $changeUpdate->rowCount();
    }
}
printf("已清理历史差异记录邮箱字段：%d\n", $changes);

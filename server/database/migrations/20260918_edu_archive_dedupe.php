<?php

use Dotenv\Dotenv;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$env = static fn (string $key, mixed $default = ''): mixed => $_ENV[$key] ?? (getenv($key) ?: $default);

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('DB_HOST', '127.0.0.1'), $env('DB_PORT', 3306), $env('DEFAULT_SCHOOL_DB', 'practical_default')),
    (string) $env('DB_USER', 'root'),
    (string) $env('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

$dryRun = in_array('--apply', $argv, true) ? false : true;
echo $dryRun ? "== DRY RUN ==\n" : "== APPLY ==\n";

/**
 * 合并重复行：保留每组最小 id 作为规范行，其余行的引用改指规范行后停用。
 */
function mergeDuplicates(PDO $pdo, bool $dryRun, string $table, string $idCol, array $groupCols, array $refs, array $extra, string $where = '1 = 1'): array
{
    $groupExpr = implode(', ', array_map(static fn (string $c): string => "`$c`", $groupCols));
    $groups = $pdo->query("SELECT $groupExpr, COUNT(*) c, MIN(`$idCol`) keep_id, GROUP_CONCAT(`$idCol` ORDER BY `$idCol`) ids
                           FROM `$table` WHERE deleted_at IS NULL AND ($where) GROUP BY $groupExpr HAVING c > 1")->fetchAll(PDO::FETCH_ASSOC);

    $merged = 0;
    $repointed = 0;
    foreach ($groups as $g) {
        $ids = array_map('intval', explode(',', $g['ids']));
        $keep = (int) $g['keep_id'];
        $drop = array_values(array_filter($ids, static fn (int $i): bool => $i !== $keep));
        if (!$drop) {
            continue;
        }
        $dropList = implode(',', $drop);
        if (!$dryRun) {
            foreach ($refs as $refTable => $refCol) {
                $stmt = $pdo->prepare("UPDATE `$refTable` SET `$refCol` = ? WHERE `$refCol` IN ($dropList)");
                $stmt->execute([$keep]);
                $repointed += $stmt->rowCount();
            }
            $pdo->exec("UPDATE `$table` SET deleted_at = NOW(), flag = 'off' WHERE `$idCol` IN ($dropList)");
        }
        $merged += count($drop);
    }
    return ['groups' => count($groups), 'merged' => $merged, 'repointed' => $repointed];
}

echo "\n=== department (按 dep_name 合并) ===\n";
$r = mergeDuplicates($pdo, $dryRun, 'department', 'dep_id', ['dep_name'], [
    'edu_student_source' => 'dep_id',
    'edu_teaching_plan_source' => 'dep_id',
    'edu_course_offering_source' => 'dep_id',
    'class' => 'dep_id',
    'profession' => 'dep_id',
    'teacher_list' => 'dep_id',
], []);
printf("  groups=%d merged=%d repointed=%d\n", $r['groups'], $r['merged'], $r['repointed']);

echo "\n=== grade_list (按 grade_name 合并) ===\n";
$r = mergeDuplicates($pdo, $dryRun, 'grade_list', 'grade_id', ['grade_name'], [
    'edu_student_source' => 'grade_id',
    'edu_teaching_plan_source' => 'grade_id',
    'class' => 'grade_id',
    'profession' => 'grade_id',
], []);
printf("  groups=%d merged=%d repointed=%d\n", $r['groups'], $r['merged'], $r['repointed']);

echo "\n=== profession (按 dep_id + profession_code 合并) ===\n";
$r = mergeDuplicates($pdo, $dryRun, 'profession', 'profession_id', ['dep_id', 'profession_code'], [
    'edu_student_source' => 'profession_id',
    'edu_teaching_plan_source' => 'profession_id',
    'class' => 'profession_id',
], [], "profession_code IS NOT NULL AND TRIM(profession_code) <> ''");
printf("  groups=%d merged=%d repointed=%d\n", $r['groups'], $r['merged'], $r['repointed']);

echo "\n=== profession (无代码时按 dep_id + profession_name 合并) ===\n";
$r = mergeDuplicates($pdo, $dryRun, 'profession', 'profession_id', ['dep_id', 'profession_name'], [
    'edu_student_source' => 'profession_id',
    'edu_teaching_plan_source' => 'profession_id',
    'class' => 'profession_id',
], [], "profession_code IS NULL OR TRIM(profession_code) = ''");
printf("  groups=%d merged=%d repointed=%d\n", $r['groups'], $r['merged'], $r['repointed']);

if (!$dryRun) {
    $pdo->exec("UPDATE department d JOIN (SELECT dep_name, MIN(dep_id) k FROM department WHERE deleted_at IS NULL GROUP BY dep_name) x ON d.dep_name = x.dep_name SET d.flag='on' WHERE d.dep_id = x.k");
}

echo "\n=== 结果核对 ===\n";
foreach ([['department', 'dep_id', 'dep_name'], ['grade_list', 'grade_id', 'grade_name'], ['profession', 'profession_id', 'profession_name'], ['class', 'class_id', 'class_num']] as [$t, $id, $nm]) {
    printf("  %-12s rows=%-6s distinct=%-6s\n", $t,
        $pdo->query("SELECT COUNT(*) FROM `$t` WHERE deleted_at IS NULL")->fetchColumn(),
        $pdo->query("SELECT COUNT(DISTINCT `$nm`) FROM `$t` WHERE deleted_at IS NULL")->fetchColumn());
}
echo "\n=== 学院 学生/教学班 是否同行 ===\n";
foreach ($pdo->query("SELECT dep_name FROM department WHERE deleted_at IS NULL GROUP BY dep_name ORDER BY dep_name") as $g) {
    $nm = $g['dep_name'];
    $department = $pdo->prepare('SELECT dep_id FROM department WHERE deleted_at IS NULL AND dep_name = ? LIMIT 1');
    $department->execute([$nm]);
    $row = $department->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        continue;
    }
    $id = (int) $row['dep_id'];
    $st = $pdo->query("SELECT COUNT(*) FROM edu_student_source WHERE dep_id=$id")->fetchColumn();
    $off = $pdo->query("SELECT COUNT(*) FROM edu_course_offering_source WHERE dep_id=$id")->fetchColumn();
    printf("  %-22s 学生=%-6s 教学班=%-6s\n", $nm, $st, $off);
}

<?php

/**
 * 重建教务基础档案并按修正后的口径重新导入源数据。
 * 用法：php database/migrations/20260919_edu_rebuild_archive.php --apply
 */

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$env = static fn (string $key, mixed $default = ''): mixed => $_ENV[$key] ?? (getenv($key) ?: $default);
$capsule = new Capsule();
foreach (['master' => $env('DB_NAME', 'practical_master'), 'mysql' => $env('DEFAULT_SCHOOL_DB', 'practical_default')] as $name => $database) {
    $capsule->addConnection([
        'driver' => 'mysql', 'host' => $env('DB_HOST', '127.0.0.1'), 'port' => (int) $env('DB_PORT', 3306),
        'database' => $database, 'username' => $env('DB_USER', 'root'), 'password' => (string) $env('DB_PASS', ''),
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
    ], $name);
}
$capsule->setAsGlobal();
$capsule->bootEloquent();

$dryRun = !in_array('--apply', $argv, true);
echo $dryRun ? "== DRY RUN ==\n" : "== APPLY ==\n";
$appMode = strtolower(trim((string) $env('APP_MODE', '')));
if (!$dryRun && $appMode !== 'test') {
    fwrite(STDERR, "拒绝执行：只有 APP_MODE=test 才允许使用 --apply。\n");
    exit(2);
}
if (!$dryRun) {
    printf("目标业务库：%s\n", (string) $env('DEFAULT_SCHOOL_DB', 'practical_default'));
}

$conn = Capsule::connection('mysql');
$tables = [
    'edu_student_source', 'edu_teaching_plan_source', 'edu_course_offering_source',
    'edu_student_stage', 'edu_teaching_plan_stage', 'edu_course_offering_stage',
    'edu_course_offering_teacher', 'edu_course_offering_class',
    'edu_import_change', 'edu_import_issue', 'edu_business_candidate',
];

echo "\n=== 待清理数据 ===\n";
foreach ($tables as $table) {
    printf("  %-32s %d\n", $table, $conn->table($table)->count());
}
printf("  %-32s %d\n", 'department', $conn->table('department')->whereNull('deleted_at')->count());
printf("  %-32s %d\n", 'grade_list', $conn->table('grade_list')->whereNull('deleted_at')->count());
printf("  %-32s %d\n", 'profession', $conn->table('profession')->whereNull('deleted_at')->count());
printf("  %-32s %d\n", 'class', $conn->table('class')->whereNull('deleted_at')->count());

if ($dryRun) {
    echo "\n未指定 --apply，未做任何修改。\n";
    exit(0);
}

$conn->transaction(function () use ($conn, $tables): void {
    foreach ($tables as $table) {
        $conn->table($table)->delete();
    }
    // 演示基础档案：保留被演示业务数据引用的学院与班级，其余清除
    $usedClasses = [];
    foreach (['internship_task_class', 'practice_project_student'] as $table) {
        foreach ($conn->table($table)->whereNotNull('class_id')->pluck('class_id') as $id) {
            $usedClasses[] = (int) $id;
        }
    }
    $usedClasses = array_values(array_unique($usedClasses));
    $usedDeps = [];
    if ($usedClasses) {
        foreach ($conn->table('class')->whereIn('class_id', $usedClasses)->pluck('dep_id') as $id) {
            $usedDeps[] = (int) $id;
        }
    }
    if ($usedDeps) {
        $conn->table('department')->whereNotIn('dep_id', $usedDeps)->delete();
    }
    $conn->table('class')->whereNotIn('class_id', $usedClasses ?: [0])->delete();
    $conn->table('profession')->delete();
    $conn->table('grade_list')->delete();
    echo "\n档案与教务源数据已清空，演示引用已保留。\n";
});

$pending = $conn->table('edu_import_batch')->whereIn('status', ['queued', 'parsing', 'validating', 'pending_confirm', 'publishing'])->get(['id', 'import_type', 'status']);
echo "\n=== 待重新处理的批次 ===\n";
foreach ($pending as $batch) {
    printf("  batch %d %s %s\n", $batch->id, $batch->import_type, $batch->status);
}
echo "\n请通过上传接口重新导入三类源文件，或对上述批次依次调用发布接口。\n";

<?php

namespace app\server\edu;

use app\model\channel\EduDataRecord;
use InvalidArgumentException;

class EduStudentSyncService
{
    public function sync(array $data, string $now): array
    {
        $connection = EduDataRecord::connection();
        $scope = $this->resolveScope($data, $now);
        $gradeId = $scope['grade_id'];
        $depId = $scope['dep_id'];
        $professionId = $scope['profession_id'];
        $classId = $scope['class_id'];
        $studentId = $this->student($connection, $data, $gradeId, $depId, $professionId, $classId, $now);

        return [
            'grade_id' => $gradeId,
            'dep_id' => $depId,
            'profession_id' => $professionId,
            'class_id' => $classId,
            'student_id' => $studentId,
            'mapping_status' => 'matched',
        ];
    }

    public function resolveScope(array $data, string $now): array
    {
        $connection = EduDataRecord::connection();
        $gradeId = $this->grade($connection, (string) ($data['grade_code'] ?? ''), (string) ($data['grade_name'] ?? ''), $now);
        $depId = $this->department($connection, (string) ($data['dep_code'] ?? ''), (string) ($data['dep_name'] ?? ''), $now);
        $professionId = $this->profession($connection, (string) ($data['profession_code'] ?? ''), (string) ($data['profession_name'] ?? ''), $depId, $gradeId, $now);
        $classId = 0;
        if ((string) ($data['class_num'] ?? '') !== '' || (string) ($data['class_name'] ?? '') !== '') {
            $classId = $this->class($connection, (string) ($data['class_num'] ?? ''), (string) ($data['class_name'] ?? ''), $depId, $professionId, $gradeId, $now);
        }
        return ['grade_id' => $gradeId, 'dep_id' => $depId, 'profession_id' => $professionId, 'class_id' => $classId ?: null];
    }

    private function grade(mixed $connection, string $code, string $name, string $now): int
    {
        if ($code === '' && $name === '') {
            throw new InvalidArgumentException('年级不能为空');
        }
        $query = $connection->table('grade_list')->whereNull('deleted_at')->where(function ($builder) use ($code, $name): void {
            if ($name !== '') {
                $builder->where('grade_name', $name);
            }
            if ($code !== '') {
                $builder->orWhere('grade_code', $code);
            }
        });
        $row = $query->lockForUpdate()->first(['grade_id', 'grade_code', 'grade_name']);
        $gradeName = $name !== '' ? $name : ($code . '级');
        if ($row) {
            $connection->table('grade_list')->where('grade_id', (int) $row->grade_id)->update([
                'grade_code' => $code !== '' ? $code : ($row->grade_code ?? null),
                'grade_name' => $gradeName,
                'flag' => 'on',
                'updated_at' => $now,
            ]);
            return (int) $row->grade_id;
        }

        return (int) $connection->table('grade_list')->insertGetId([
            'grade_uuid' => $this->uuid(),
            'grade_code' => $code !== '' ? $code : null,
            'grade_name' => $gradeName,
            'flag' => 'on',
            'sort' => (int) preg_replace('/\D+/', '', $code),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function department(mixed $connection, string $code, string $name, string $now): int
    {
        if ($name === '' && $code === '') {
            throw new InvalidArgumentException('学院不能为空');
        }
        $query = $connection->table('department')->whereNull('deleted_at')->where(function ($builder) use ($code, $name): void {
            if ($name !== '') {
                $builder->where('dep_name', $name);
            }
            if ($code !== '') {
                $builder->orWhere('dep_code', $code);
            }
        });
        $row = $query->lockForUpdate()->first(['dep_id', 'dep_code', 'dep_name']);
        if ($row) {
            $connection->table('department')->where('dep_id', (int) $row->dep_id)->update([
                'dep_code' => $code !== '' ? $code : ($row->dep_code ?? null),
                'dep_name' => $name !== '' ? $name : ($row->dep_name ?? $code),
                'flag' => 'on',
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
            return (int) $row->dep_id;
        }

        return (int) $connection->table('department')->insertGetId([
            'dep_uuid' => $this->uuid(),
            'dep_code' => $code !== '' ? $code : null,
            'dep_name' => $name ?: $code,
            'parent_id' => 0,
            'sort' => 0,
            'flag' => 'on',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 专业唯一口径为“年级 + 学院 + 专业代码”，专业代码缺失时回退专业名称。
     */
    private function profession(mixed $connection, string $code, string $name, int $depId, int $gradeId, string $now): int
    {
        if ($code === '' && $name === '') {
            throw new InvalidArgumentException('专业不能为空');
        }
        $query = $connection->table('profession')
            ->where('dep_id', $depId)
            ->where('grade_id', $gradeId)
            ->whereNull('deleted_at');
        if ($code !== '') {
            $query->where('profession_code', $code);
        } else {
            $query->where('profession_name', $name);
        }
        $row = $query->lockForUpdate()->first(['profession_id', 'profession_code', 'profession_name']);
        if ($row) {
            $connection->table('profession')->where('profession_id', (int) $row->profession_id)->update([
                'profession_code' => $code !== '' ? $code : ($row->profession_code ?? null),
                'profession_name' => $name !== '' ? $name : ($row->profession_name ?? $code),
                'flag' => 'on',
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
            return (int) $row->profession_id;
        }

        return (int) $connection->table('profession')->insertGetId([
            'profession_uuid' => $this->uuid(),
            'profession_code' => $code !== '' ? $code : null,
            'profession_name' => $name ?: $code,
            'dep_id' => $depId,
            'grade_id' => $gradeId,
            'sort' => 0,
            'flag' => 'on',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 班级按班号优先匹配，班号缺失或未命中时回退班级名称。
     */
    public function resolveClassId(string $classNum, string $className): ?int
    {
        return $this->findClassId(EduDataRecord::connection(), $classNum, $className);
    }

    private function findClassId(mixed $connection, string $classNum, string $className): ?int
    {
        foreach ([['class_num', $classNum], ['class_name', $className], ['class_short_name', $className]] as [$column, $value]) {
            if ($value === '') {
                continue;
            }
            $row = $connection->table('class')->where($column, $value)->whereNull('deleted_at')->first(['class_id']);
            if ($row) {
                return (int) $row->class_id;
            }
        }
        return null;
    }

    private function class(mixed $connection, string $classNum, string $name, int $depId, int $professionId, int $gradeId, string $now): int
    {
        if ($classNum === '' && $name === '') {
            throw new InvalidArgumentException('班级不能为空');
        }
        $classId = $this->findClassId($connection, $classNum, $name);
        if ($classId) {
            $connection->table('class')->where('class_id', $classId)->update([
                'class_num' => $classNum !== '' ? $classNum : null,
                'class_name' => $name ?: $classNum,
                'dep_id' => $depId,
                'profession_id' => $professionId,
                'grade_id' => $gradeId,
                'flag' => 'on',
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
            return $classId;
        }

        return (int) $connection->table('class')->insertGetId([
            'class_uuid' => $this->uuid(),
            'class_num' => $classNum !== '' ? $classNum : null,
            'class_name' => $name ?: $classNum,
            'dep_id' => $depId,
            'profession_id' => $professionId,
            'grade_id' => $gradeId,
            'sort' => 0,
            'flag' => 'on',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function student(mixed $connection, array $data, int $gradeId, int $depId, int $professionId, int $classId, string $now): int
    {
        $studentNum = trim((string) ($data['student_num'] ?? ''));
        if ($studentNum === '') {
            throw new InvalidArgumentException('学号不能为空');
        }
        $status = (string) ($data['source_status'] ?? 'active') === 'active' ? 'enabled' : 'disabled';
        $row = $connection->table('students')->where('student_num', $studentNum)->whereNull('deleted_at')->lockForUpdate()->first(['student_id']);
        $values = [
            'name' => (string) ($data['student_name'] ?? ''),
            'student_num' => $studentNum,
            'grade_id' => $gradeId,
            'dep_id' => $depId,
            'profession_id' => $professionId,
            'class_id' => $classId,
            'class_num' => (string) ($data['class_num'] ?? ''),
            'status' => $status,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
        if ($row) {
            $connection->table('students')->where('student_id', (int) $row->student_id)->update($values);
            return (int) $row->student_id;
        }
        return (int) $connection->table('students')->insertGetId(array_merge($values, [
            'student_uuid' => $this->uuid(),
            'created_at' => $now,
        ]));
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

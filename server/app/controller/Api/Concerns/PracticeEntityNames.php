<?php

namespace app\controller\Api\Concerns;

use support\Request;

trait PracticeEntityNames
{
    /**
     * 获取实验实训实体名称。
     */
    private function practiceEntityName(Request $request): string
    {
        return match ((string) $request->input('entity', '')) {
            'plan' => '教学计划',
            'schedule' => '课表安排',
            'syllabus' => '大纲',
            'lessonPlan' => '教案',
            'gradeRule' => '成绩比例',
            'score' => '成绩',
            'reflection' => '反思报告',
            'room' => '实验实训室',
            default => '记录',
        };
    }
}

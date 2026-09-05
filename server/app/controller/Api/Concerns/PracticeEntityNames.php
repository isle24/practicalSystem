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
            'plan' => '开课任务',
            'schedule' => '课表安排',
            'syllabus' => '大纲',
            'lessonPlan' => '教案',
            'gradeRule' => '成绩方案',
            'score' => '成绩',
            'reflection' => '课程教学反思',
            'room' => '实验实训室',
            default => '记录',
        };
    }
}

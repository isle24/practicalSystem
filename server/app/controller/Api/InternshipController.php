<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\OperationLogContext;
use app\server\internship\InternshipService;
use support\Request;
use support\Response;
use Throwable;

class InternshipController
{
    use Responds;

    /**
     * 查看实习总览
     */
    #[OperationLog('查看实习总览')]
    public function overview(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->overview($request));
    }

    /**
     * 获取实习筛选选项
     */
    #[OperationLog('获取实习筛选选项')]
    public function options(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->options($request));
    }

    /**
     * 查询实习基地
     */
    #[OperationLog('查询实习基地')]
    public function bases(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->bases($request));
    }

    /**
     * 查看实习基地详情
     */
    #[OperationLog('查看实习基地详情')]
    public function baseDetail(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->baseDetail($request));
    }

    /**
     * 保存实习基地
     */
    #[OperationLog('保存实习基地')]
    public function saveBase(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveBase($request));
    }

    /**
     * 查询基地流程记录
     */
    #[OperationLog('查询基地流程记录')]
    public function baseFlows(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->baseFlows($request));
    }

    /**
     * 保存基地流程记录
     */
    #[OperationLog('保存基地流程记录')]
    public function saveBaseFlow(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveBaseFlow($request));
    }

    /**
     * 审核基地流程记录
     */
    #[OperationLog('审核基地流程记录')]
    public function reviewBaseFlow(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewBaseFlow($request));
    }

    /**
     * 查询企业导师
     */
    #[OperationLog('查询企业导师')]
    public function mentors(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->mentors($request));
    }

    /**
     * 保存企业导师
     */
    #[OperationLog('保存企业导师')]
    public function saveMentor(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveMentor($request));
    }

    /**
     * 查询实习任务
     */
    #[OperationLog('查询实习任务')]
    public function arrangements(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->arrangements($request));
    }

    /**
     * 查看实习任务详情
     */
    #[OperationLog('查看实习任务详情')]
    public function arrangementDetail(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->arrangementDetail($request));
    }

    /**
     * 查询实习任务变更
     */
    #[OperationLog('查询实习任务变更')]
    public function arrangementChanges(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->arrangementChanges($request));
    }

    /**
     * 保存实习任务
     */
    #[OperationLog('保存实习任务')]
    public function saveArrangement(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveArrangement($request));
    }

    /**
     * 提交实习任务变更
     */
    #[OperationLog('提交实习任务变更')]
    public function saveArrangementChange(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveArrangementChange($request));
    }

    /**
     * 审核实习任务变更
     */
    #[OperationLog('审核实习任务变更')]
    public function reviewArrangementChange(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewArrangementChange($request));
    }

    /**
     * 导入实习任务学生绑定
     */
    #[OperationLog('导入实习任务学生绑定')]
    public function importArrangementAssignments(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->importArrangementAssignments($request));
    }

    /**
     * 查询实习申请
     */
    #[OperationLog('查询实习申请')]
    public function applications(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->applications($request));
    }

    /**
     * 保存实习申请
     */
    #[OperationLog('保存实习申请')]
    public function saveApplication(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveApplication($request));
    }

    /**
     * 提交实习申请
     */
    #[OperationLog('提交实习申请')]
    public function submitApplication(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->submitApplication($request));
    }

    /**
     * 审核实习申请
     */
    #[OperationLog('审核实习申请')]
    public function reviewApplication(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewApplication($request));
    }

    /**
     * 读取实习审核草稿
     */
    #[OperationLog('读取实习审核草稿')]
    public function reviewDraft(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewDraft($request));
    }

    /**
     * 保存实习审核草稿
     */
    #[OperationLog('保存实习审核草稿')]
    public function saveReviewDraft(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveReviewDraft($request));
    }

    /**
     * 查看实习流程记录
     */
    #[OperationLog('查看实习流程记录')]
    public function timeline(Request $request): Response
    {
        OperationLogContext::setName('查看' . $this->workflowEntityName($request) . '流程记录');
        return $this->handle(fn (): array => $this->service()->timeline($request));
    }

    /**
     * 发起通过后修改
     */
    #[OperationLog('发起通过后修改')]
    public function requestModification(Request $request): Response
    {
        OperationLogContext::setName('发起' . $this->workflowEntityName($request) . '通过后修改');
        return $this->handle(fn (): array => $this->service()->requestModification($request));
    }

    /**
     * 查询实习任务绑定
     */
    #[OperationLog('查询实习任务绑定')]
    public function pairs(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->pairs($request));
    }

    /**
     * 保存实习任务绑定
     */
    #[OperationLog('保存实习任务绑定')]
    public function savePair(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->savePair($request));
    }

    /**
     * 删除实习任务绑定
     */
    #[OperationLog('删除实习任务绑定')]
    public function removePair(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->removePair($request));
    }

    /**
     * 查询实习签到
     */
    #[OperationLog('查询实习签到')]
    public function signIns(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->signIns($request));
    }

    /**
     * 提交实习签到
     */
    #[OperationLog('提交实习签到')]
    public function saveSignIn(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveSignIn($request));
    }

    /**
     * 查询实习日志
     */
    #[OperationLog('查询实习日志')]
    public function journals(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->journals($request));
    }

    /**
     * 保存实习日志
     */
    #[OperationLog('保存实习日志')]
    public function saveJournal(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveJournal($request));
    }

    /**
     * 审核实习日志
     */
    #[OperationLog('审核实习日志')]
    public function reviewJournal(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewJournal($request));
    }

    /**
     * 查询实习报告
     */
    #[OperationLog('查询实习报告')]
    public function reports(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reports($request));
    }

    /**
     * 保存实习报告
     */
    #[OperationLog('保存实习报告')]
    public function saveReport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveReport($request));
    }

    /**
     * 审核实习报告
     */
    #[OperationLog('审核实习报告')]
    public function reviewReport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewReport($request));
    }

    /**
     * 查询延期申请
     */
    #[OperationLog('查询延期申请')]
    public function delays(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->delays($request));
    }

    /**
     * 保存延期申请
     */
    #[OperationLog('保存延期申请')]
    public function saveDelay(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveDelay($request));
    }

    /**
     * 审核延期申请
     */
    #[OperationLog('审核延期申请')]
    public function reviewDelay(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewDelay($request));
    }

    /**
     * 查询实习成绩
     */
    #[OperationLog('查询实习成绩')]
    public function scores(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->scores($request));
    }

    /**
     * 查询课程成绩
     */
    #[OperationLog('查询课程成绩')]
    public function courseScores(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->courseScores($request));
    }

    /**
     * 核定课程成绩
     */
    #[OperationLog('核定课程成绩')]
    public function saveCourseScore(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveCourseScore($request));
    }

    /**
     * 查看实习统计报表
     */
    #[OperationLog('查看实习统计报表')]
    public function stats(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->stats($request));
    }

    /**
     * 查看实习归档材料
     */
    #[OperationLog('查看实习归档材料')]
    public function archiveMaterials(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->archiveMaterials($request));
    }

    /**
     * 保存实习成绩
     */
    #[OperationLog('保存实习成绩')]
    public function saveScore(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveScore($request));
    }

    /**
     * 查询实习计划
     */
    #[OperationLog('查询实习计划')]
    public function plans(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->plans($request));
    }

    /**
     * 保存实习计划
     */
    #[OperationLog('保存实习计划')]
    public function savePlan(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->savePlan($request));
    }

    /**
     * 审核实习计划
     */
    #[OperationLog('审核实习计划')]
    public function reviewPlan(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewPlan($request));
    }

    /**
     * 查询保险记录
     */
    #[OperationLog('查询保险记录')]
    public function insurances(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->insurances($request));
    }

    /**
     * 保存保险记录
     */
    #[OperationLog('保存保险记录')]
    public function saveInsurance(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveInsurance($request));
    }

    /**
     * 查询安全承诺书
     */
    #[OperationLog('查询安全承诺书')]
    public function safetyLetters(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->safetyLetters($request));
    }

    /**
     * 保存安全承诺书
     */
    #[OperationLog('保存安全承诺书')]
    public function saveSafetyLetter(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveSafetyLetter($request));
    }

    /**
     * 查询实习大纲指导书
     */
    #[OperationLog('查询实习大纲指导书')]
    public function syllabusGuides(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->syllabusGuides($request));
    }

    /**
     * 保存实习大纲指导书
     */
    #[OperationLog('保存实习大纲指导书')]
    public function saveSyllabusGuide(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveSyllabusGuide($request));
    }

    /**
     * 查询实施计划表
     */
    #[OperationLog('查询实施计划表')]
    public function implementationSheets(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->implementationSheets($request));
    }

    /**
     * 保存实施计划表
     */
    #[OperationLog('保存实施计划表')]
    public function saveImplementationSheet(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveImplementationSheet($request));
    }

    /**
     * 查询教师工作报告
     */
    #[OperationLog('查询教师工作报告')]
    public function teacherWorkReports(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->teacherWorkReports($request));
    }

    /**
     * 保存教师工作报告
     */
    #[OperationLog('保存教师工作报告')]
    public function saveTeacherWorkReport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveTeacherWorkReport($request));
    }

    /**
     * 查询实习巡查记录
     */
    #[OperationLog('查询实习巡查记录')]
    public function inspections(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->inspections($request));
    }

    /**
     * 保存实习巡查记录
     */
    #[OperationLog('保存实习巡查记录')]
    public function saveInspection(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveInspection($request));
    }

    /**
     * 审核实习文档流程
     */
    #[OperationLog('审核实习文档流程')]
    public function reviewDocument(Request $request): Response
    {
        OperationLogContext::setName('审核' . $this->workflowEntityName($request) . '流程');
        return $this->handle(fn (): array => $this->service()->reviewDocument($request));
    }

    private function handle(callable $callback): Response
    {
        try {
            return $this->ok($callback());
        } catch (Throwable $exception) {
            $status = (int) $exception->getCode();
            if (!in_array($status, [401, 403, 404, 409, 422, 429], true)) {
                $status = $exception->getMessage() === '请先登录' ? 401 : 400;
            }
            $code = match ($status) {
                401 => 40100,
                403 => str_contains($exception->getMessage(), '数据') ? 40301 : 40300,
                404 => 40400,
                409 => 40900,
                422 => 42200,
                429 => 42900,
                default => 40001,
            };
            if ((int) $exception->getCode() >= 42201 && (int) $exception->getCode() <= 42299) {
                $code = (int) $exception->getCode();
                $status = 422;
            }

            return $this->fail($code, $exception->getMessage(), $status);
        }
    }

    private function service(): InternshipService
    {
        return new InternshipService();
    }

    /**
     * 获取流程记录实体名称。
     */
    private function workflowEntityName(Request $request): string
    {
        return match ((string) $request->input('entity', '')) {
            'application' => '实习申请',
            'arrangement' => '实习任务',
            'arrangement_change' => '实习任务变更',
            'sign_in' => '实习签到',
            'journal' => '实习日志',
            'report' => '实习报告',
            'score' => '实习成绩',
            'plan' => '实习计划',
            'delay' => '延期申请',
            'insurance' => '保险记录',
            'safety_letter' => '安全承诺书',
            'syllabus_guide' => '实习大纲指导书',
            'implementation_sheet' => '实施计划表',
            'teacher_work_report' => '教师工作报告',
            'inspection' => '实习巡查记录',
            'base_application' => '基地申报',
            'base_usage' => '基地使用',
            'base_result' => '基地成果',
            'base_expense' => '基地费用',
            default => '实习流程',
        };
    }
}

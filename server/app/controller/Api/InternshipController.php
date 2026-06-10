<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\internship\InternshipService;
use support\Request;
use support\Response;
use Throwable;

class InternshipController
{
    use Responds;

    #[OperationLog('查看实习总览')]
    public function overview(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->overview($request));
    }

    #[OperationLog('获取实习筛选选项')]
    public function options(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->options($request));
    }

    #[OperationLog('查询实习基地')]
    public function bases(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->bases($request));
    }

    #[OperationLog('保存实习基地')]
    public function saveBase(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveBase($request));
    }

    #[OperationLog('查询基地流程记录')]
    public function baseFlows(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->baseFlows($request));
    }

    #[OperationLog('保存基地流程记录')]
    public function saveBaseFlow(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveBaseFlow($request));
    }

    #[OperationLog('查询企业导师')]
    public function mentors(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->mentors($request));
    }

    #[OperationLog('保存企业导师')]
    public function saveMentor(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveMentor($request));
    }

    #[OperationLog('查询实习任务')]
    public function arrangements(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->arrangements($request));
    }

    #[OperationLog('查看实习任务详情')]
    public function arrangementDetail(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->arrangementDetail($request));
    }

    #[OperationLog('查询实习任务变更')]
    public function arrangementChanges(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->arrangementChanges($request));
    }

    #[OperationLog('保存实习任务')]
    public function saveArrangement(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveArrangement($request));
    }

    #[OperationLog('提交实习任务变更')]
    public function saveArrangementChange(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveArrangementChange($request));
    }

    #[OperationLog('审核实习任务变更')]
    public function reviewArrangementChange(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewArrangementChange($request));
    }

    #[OperationLog('导入实习任务学生绑定')]
    public function importArrangementAssignments(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->importArrangementAssignments($request));
    }

    #[OperationLog('查询实习申请')]
    public function applications(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->applications($request));
    }

    #[OperationLog('保存实习申请')]
    public function saveApplication(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveApplication($request));
    }

    #[OperationLog('提交实习申请')]
    public function submitApplication(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->submitApplication($request));
    }

    #[OperationLog('审核实习申请')]
    public function reviewApplication(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewApplication($request));
    }

    #[OperationLog('查看实习流程记录')]
    public function timeline(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->timeline($request));
    }

    #[OperationLog('发起通过后修改')]
    public function requestModification(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->requestModification($request));
    }

    #[OperationLog('查询实习配对')]
    public function pairs(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->pairs($request));
    }

    #[OperationLog('保存实习配对')]
    public function savePair(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->savePair($request));
    }

    #[OperationLog('删除实习配对')]
    public function removePair(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->removePair($request));
    }

    #[OperationLog('查询实习签到')]
    public function signIns(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->signIns($request));
    }

    #[OperationLog('提交实习签到')]
    public function saveSignIn(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveSignIn($request));
    }

    #[OperationLog('查询实习日志')]
    public function journals(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->journals($request));
    }

    #[OperationLog('保存实习日志')]
    public function saveJournal(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveJournal($request));
    }

    #[OperationLog('审核实习日志')]
    public function reviewJournal(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewJournal($request));
    }

    #[OperationLog('查询实习报告')]
    public function reports(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reports($request));
    }

    #[OperationLog('保存实习报告')]
    public function saveReport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveReport($request));
    }

    #[OperationLog('审核实习报告')]
    public function reviewReport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewReport($request));
    }

    #[OperationLog('查询延期申请')]
    public function delays(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->delays($request));
    }

    #[OperationLog('保存延期申请')]
    public function saveDelay(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveDelay($request));
    }

    #[OperationLog('审核延期申请')]
    public function reviewDelay(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewDelay($request));
    }

    #[OperationLog('查询实习成绩')]
    public function scores(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->scores($request));
    }

    #[OperationLog('查询课程成绩')]
    public function courseScores(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->courseScores($request));
    }

    #[OperationLog('查看实习统计报表')]
    public function stats(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->stats($request));
    }

    #[OperationLog('查看实习归档材料')]
    public function archiveMaterials(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->archiveMaterials($request));
    }

    #[OperationLog('保存实习成绩')]
    public function saveScore(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveScore($request));
    }

    #[OperationLog('查询实习计划')]
    public function plans(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->plans($request));
    }

    #[OperationLog('保存实习计划')]
    public function savePlan(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->savePlan($request));
    }

    #[OperationLog('审核实习计划')]
    public function reviewPlan(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewPlan($request));
    }

    #[OperationLog('查询保险记录')]
    public function insurances(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->insurances($request));
    }

    #[OperationLog('保存保险记录')]
    public function saveInsurance(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveInsurance($request));
    }

    #[OperationLog('查询安全承诺书')]
    public function safetyLetters(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->safetyLetters($request));
    }

    #[OperationLog('保存安全承诺书')]
    public function saveSafetyLetter(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveSafetyLetter($request));
    }

    #[OperationLog('查询实习大纲指导书')]
    public function syllabusGuides(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->syllabusGuides($request));
    }

    #[OperationLog('保存实习大纲指导书')]
    public function saveSyllabusGuide(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveSyllabusGuide($request));
    }

    #[OperationLog('查询实施计划表')]
    public function implementationSheets(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->implementationSheets($request));
    }

    #[OperationLog('保存实施计划表')]
    public function saveImplementationSheet(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveImplementationSheet($request));
    }

    #[OperationLog('查询教师工作报告')]
    public function teacherWorkReports(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->teacherWorkReports($request));
    }

    #[OperationLog('保存教师工作报告')]
    public function saveTeacherWorkReport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveTeacherWorkReport($request));
    }

    #[OperationLog('查询实习巡查记录')]
    public function inspections(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->inspections($request));
    }

    #[OperationLog('保存实习巡查记录')]
    public function saveInspection(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveInspection($request));
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
}

<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\Responds;
use app\server\OperationLogContext;
use app\server\socialpractice\SocialPracticeService;
use support\Request;
use support\Response;
use Throwable;

class SocialPracticeController
{
    use Responds;

    /** 查看社会实践总览。 */
    #[OperationLog('查看社会实践总览')]
    public function overview(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->overview($request));
    }

    /** 查看社会实践统计报表。 */
    #[OperationLog('查看社会实践统计报表')]
    public function statistics(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->statistics($request));
    }

    /** 导出社会实践统计报表。 */
    #[OperationLog('导出社会实践统计报表')]
    public function exportStatistics(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->exportStatistics($request), '导出任务已创建');
    }

    /** 下载社会实践计划导入模板。 */
    #[OperationLog('下载社会实践计划导入模板')]
    public function planImportTemplate(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->planImportTemplate($request));
    }

    /** 预览社会实践计划 Excel 导入。 */
    #[OperationLog('预览社会实践计划Excel导入')]
    public function previewPlanImport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->previewPlanImport($request));
    }

    /** 确认社会实践计划 Excel 导入。 */
    #[OperationLog('确认社会实践计划Excel导入')]
    public function confirmPlanImport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->confirmPlanImport($request), '导入完成');
    }

    /** 获取社会实践选项。 */
    #[OperationLog('获取社会实践筛选选项')]
    public function options(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->options($request));
    }

    /** 获取社会实践计划可选学生。 */
    #[OperationLog('获取社会实践计划可选学生')]
    public function eligibleStudents(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->eligibleStudents($request));
    }

    /** 查询社会实践列表。 */
    #[OperationLog('查询社会实践列表')]
    public function list(Request $request): Response
    {
        OperationLogContext::setName('查询社会实践' . (string) $request->input('resource', '') . '列表');
        return $this->handle(fn (): array => $this->service()->list($request));
    }

    /** 查看社会实践详情。 */
    #[OperationLog('查看社会实践详情')]
    public function detail(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->detail($request));
    }

    /** 查看社会实践流程记录。 */
    #[OperationLog('查看社会实践流程记录')]
    public function timeline(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->timeline($request));
    }

    /** 保存社会实践草稿。 */
    #[OperationLog('保存社会实践草稿')]
    public function save(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->save($request), '草稿已保存');
    }

    /** 提交社会实践审核。 */
    #[OperationLog('提交社会实践审核')]
    public function submit(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->submit($request), '已提交审核');
    }

    /** 审核社会实践记录。 */
    #[OperationLog('审核社会实践记录')]
    public function review(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->review($request), '审核已提交');
    }

    /** 发布社会实践计划。 */
    #[OperationLog('发布社会实践计划')]
    public function publish(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->publish($request), '计划已发布');
    }

    /** 发起社会实践通过后修改。 */
    #[OperationLog('发起社会实践通过后修改')]
    public function requestModification(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->requestModification($request), '已发起修改');
    }

    /** 分配集中实践指导教师。 */
    #[OperationLog('分配社会实践指导教师')]
    public function assignTeachers(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->assignTeachers($request), '教师已分配');
    }

    /** 分配集中实践学生。 */
    #[OperationLog('分配社会实践学生')]
    public function assignStudents(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->assignStudents($request), '学生已分配');
    }

    /** 分配分散实践指导教师。 */
    #[OperationLog('分配分散实践指导教师')]
    public function assignDeclarationTeacher(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->assignDeclarationTeacher($request), '指导教师已分配');
    }

    /** 确认社会实践团队成员。 */
    #[OperationLog('确认社会实践团队成员')]
    public function confirmMember(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->confirmMember($request), '成员确认已保存');
    }

    /** 确认社会实践指导教师。 */
    #[OperationLog('确认社会实践指导教师')]
    public function confirmTeacher(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->confirmTeacher($request), '教师确认已保存');
    }

    /** 学生重新选择社会实践指导教师。 */
    #[OperationLog('重新选择社会实践指导教师')]
    public function reselectTeacher(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reselectTeacher($request), '已重新选择指导教师');
    }

    /** 保存社会实践材料。 */
    #[OperationLog('保存社会实践材料')]
    public function saveMaterial(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveMaterial($request), '材料已保存');
    }

    /** 保存社会实践签到。 */
    #[OperationLog('保存社会实践签到')]
    public function saveSignIn(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveSignIn($request), '签到成功');
    }

    /** 保存社会实践补签申请。 */
    #[OperationLog('保存社会实践补签申请')]
    public function savePatchSign(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->savePatchSign($request), '补签申请已保存');
    }

    /** 保存社会实践成绩。 */
    #[OperationLog('保存社会实践成绩')]
    public function saveScore(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveScore($request), '成绩已保存');
    }

    /** 归档社会实践记录。 */
    #[OperationLog('归档社会实践记录')]
    public function archive(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->archive($request), '归档完成');
    }

    /** 读取社会实践审核草稿。 */
    #[OperationLog('读取社会实践审核草稿')]
    public function reviewDraft(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewDraft($request));
    }

    /** 保存社会实践审核草稿。 */
    #[OperationLog('保存社会实践审核草稿')]
    public function saveReviewDraft(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveReviewDraft($request), '审核草稿已保存');
    }

    /** 创建社会实践服务。 */
    private function service(): SocialPracticeService
    {
        return new SocialPracticeService();
    }

    /** 统一处理社会实践接口响应。 */
    private function handle(callable $callback, string $message = 'ok'): Response
    {
        try {
            return $this->ok($callback(), $message);
        } catch (Throwable $exception) {
            $exceptionCode = (int) $exception->getCode();
            if (in_array($exceptionCode, [40100, 40300, 40301, 40400, 40900, 42900], true)) {
                $status = intdiv($exceptionCode, 100);
                return $this->fail($exceptionCode, $exception->getMessage(), $status);
            }
            if ($exceptionCode >= 42201 && $exceptionCode <= 42299) {
                return $this->fail($exceptionCode, $exception->getMessage(), 422);
            }
            $status = in_array($exceptionCode, [401, 403, 404, 409, 422, 429], true) ? $exceptionCode : 400;
            $code = match ($status) {
                401 => 40100,
                403 => 40300,
                404 => 40400,
                409 => 40900,
                422 => 42200,
                429 => 42900,
                default => 40001,
            };
            return $this->fail($code, $exception->getMessage(), $status);
        }
    }
}

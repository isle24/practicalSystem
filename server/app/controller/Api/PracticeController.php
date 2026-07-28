<?php

namespace app\controller\Api;

use app\attribute\OperationLog;
use app\controller\Api\Concerns\PracticeEntityNames;
use app\controller\Api\Concerns\Responds;
use app\server\OperationLogContext;
use app\server\practice\PracticeService;
use InvalidArgumentException;
use support\Request;
use support\Response;
use Throwable;

class PracticeController
{
    use Responds;
    use PracticeEntityNames;

    /** 查看实验实训总览。 */
    #[OperationLog('查看实验实训总览')]
    public function overview(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->overview($request));
    }

    /** 获取实验实训筛选选项。 */
    #[OperationLog('获取实验实训筛选选项')]
    public function options(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->options($request));
    }

    /** 查询课节配置。 */
    #[OperationLog('查询实验实训课节配置')]
    public function periods(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->periods($request));
    }

    /** 保存课节配置。 */
    #[OperationLog('保存实验实训课节配置')]
    public function savePeriod(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->savePeriod($request));
    }

    /** 查询专业周课表。 */
    #[OperationLog('查询实验实训专业周课表')]
    public function scheduleWeek(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->scheduleWeek($request));
    }

    /** 查询实验实训列表。 */
    #[OperationLog('查询实验实训列表')]
    public function list(Request $request): Response
    {
        OperationLogContext::setName('查询' . $this->moduleName($request) . $this->practiceEntityName($request) . '列表');
        return $this->handle(fn (): array => $this->service($request)->list($request));
    }

    /** 保存实验实训记录。 */
    #[OperationLog('保存实验实训记录')]
    public function save(Request $request): Response
    {
        OperationLogContext::setName('保存' . $this->moduleName($request) . $this->practiceEntityName($request));
        return $this->handle(fn (): array => $this->service($request)->save($request));
    }

    /** 审核实验实训记录。 */
    #[OperationLog('审核实验实训记录')]
    public function review(Request $request): Response
    {
        OperationLogContext::setName('审核' . $this->moduleName($request) . $this->practiceEntityName($request));
        return $this->handle(fn (): array => $this->service($request)->review($request));
    }

    /** 读取实验实训审核草稿。 */
    #[OperationLog('读取实验实训审核草稿')]
    public function reviewDraft(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->reviewDraft($request));
    }

    /** 保存实验实训审核草稿。 */
    #[OperationLog('保存实验实训审核草稿')]
    public function saveReviewDraft(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->saveReviewDraft($request));
    }

    /** 发起实验实训通过后修改。 */
    #[OperationLog('发起实验实训通过后修改')]
    public function requestModification(Request $request): Response
    {
        OperationLogContext::setName('发起' . $this->moduleName($request) . $this->practiceEntityName($request) . '通过后修改');
        return $this->handle(fn (): array => $this->service($request)->requestModification($request));
    }

    /** 查看实验实训流程记录。 */
    #[OperationLog('查看实验实训流程记录')]
    public function timeline(Request $request): Response
    {
        OperationLogContext::setName('查看' . $this->moduleName($request) . $this->practiceEntityName($request) . '流程记录');
        return $this->handle(fn (): array => $this->service($request)->timeline($request));
    }

    /** 查询实验实训签到。 */
    #[OperationLog('查询实验实训签到')]
    public function signIns(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->signIns($request));
    }

    /** 保存实验实训签到。 */
    #[OperationLog('保存实验实训签到')]
    public function saveSignIn(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->saveSignIn($request));
    }

    /** 查询实验实训日志。 */
    #[OperationLog('查询实验实训日志')]
    public function journals(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->journals($request));
    }

    /** 保存实验实训日志。 */
    #[OperationLog('保存实验实训日志')]
    public function saveJournal(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->saveJournal($request));
    }

    /** 审核实验实训日志。 */
    #[OperationLog('审核实验实训日志')]
    public function reviewJournal(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->reviewJournal($request));
    }

    /** 查询实验实训报告。 */
    #[OperationLog('查询实验实训报告')]
    public function reports(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->reports($request));
    }

    /** 保存实验实训报告。 */
    #[OperationLog('保存实验实训报告')]
    public function saveReport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->saveReport($request));
    }

    /** 审核实验实训报告。 */
    #[OperationLog('审核实验实训报告')]
    public function reviewReport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->reviewReport($request));
    }

    /** 发起执行记录通过后修改。 */
    #[OperationLog('发起实验实训执行记录通过后修改')]
    public function requestExecutionModification(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->requestExecutionModification($request));
    }

    /** 保存实验实训成绩。 */
    #[OperationLog('保存实验实训成绩')]
    public function saveScore(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->saveScore($request));
    }

    /** 查看实验实训执行记录。 */
    #[OperationLog('查看实验实训执行记录')]
    public function executionTimeline(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service($request)->executionTimeline($request));
    }

    /** 统一处理接口响应。 */
    private function handle(callable $callback): Response
    {
        try {
            return $this->ok($callback());
        } catch (Throwable $exception) {
            $exceptionCode = (int) $exception->getCode();
            if (in_array($exceptionCode, [40100, 40300, 40301, 40400, 40900, 42900], true)) {
                $status = intdiv($exceptionCode, 100);
                return $this->fail($exceptionCode, $exception->getMessage(), $status);
            }
            if ($exceptionCode >= 42201 && $exceptionCode <= 42299) {
                return $this->fail($exceptionCode, $exception->getMessage(), 422);
            }

            $status = $exceptionCode;
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
            return $this->fail($code, $exception->getMessage(), $status);
        }
    }

    /** 创建实验实训服务。 */
    private function service(Request $request): PracticeService
    {
        return new PracticeService($this->moduleType($request));
    }

    /** 读取实验实训类别。 */
    private function moduleType(Request $request): string
    {
        $moduleType = trim((string) $request->input('module_type', 'all'));
        if (!in_array($moduleType, ['all', 'lab', 'training'], true)) {
            throw new InvalidArgumentException('module_type 无效');
        }

        return $moduleType;
    }

    /** 读取实验实训类别名称。 */
    private function moduleName(Request $request): string
    {
        return [
            'lab' => '实验',
            'training' => '实训',
            'all' => '实验实训',
        ][$this->moduleType($request)];
    }
}

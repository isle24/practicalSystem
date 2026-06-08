<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\server\internship\InternshipService;
use support\Request;
use support\Response;
use Throwable;

class InternshipController
{
    use Responds;

    public function overview(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->overview($request));
    }

    public function options(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->options($request));
    }

    public function bases(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->bases($request));
    }

    public function saveBase(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveBase($request));
    }

    public function baseFlows(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->baseFlows($request));
    }

    public function saveBaseFlow(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveBaseFlow($request));
    }

    public function mentors(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->mentors($request));
    }

    public function saveMentor(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveMentor($request));
    }

    public function arrangements(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->arrangements($request));
    }

    public function saveArrangement(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveArrangement($request));
    }

    public function applications(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->applications($request));
    }

    public function saveApplication(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveApplication($request));
    }

    public function submitApplication(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->submitApplication($request));
    }

    public function reviewApplication(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewApplication($request));
    }

    public function timeline(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->timeline($request));
    }

    public function requestModification(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->requestModification($request));
    }

    public function pairs(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->pairs($request));
    }

    public function savePair(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->savePair($request));
    }

    public function removePair(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->removePair($request));
    }

    public function signIns(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->signIns($request));
    }

    public function saveSignIn(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveSignIn($request));
    }

    public function journals(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->journals($request));
    }

    public function saveJournal(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveJournal($request));
    }

    public function reviewJournal(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewJournal($request));
    }

    public function reports(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reports($request));
    }

    public function saveReport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveReport($request));
    }

    public function reviewReport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewReport($request));
    }

    public function delays(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->delays($request));
    }

    public function saveDelay(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveDelay($request));
    }

    public function reviewDelay(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewDelay($request));
    }

    public function scores(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->scores($request));
    }

    public function stats(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->stats($request));
    }

    public function archiveMaterials(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->archiveMaterials($request));
    }

    public function saveScore(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveScore($request));
    }

    public function plans(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->plans($request));
    }

    public function savePlan(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->savePlan($request));
    }

    public function reviewPlan(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->reviewPlan($request));
    }

    public function insurances(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->insurances($request));
    }

    public function saveInsurance(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveInsurance($request));
    }

    public function safetyLetters(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->safetyLetters($request));
    }

    public function saveSafetyLetter(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveSafetyLetter($request));
    }

    public function syllabusGuides(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->syllabusGuides($request));
    }

    public function saveSyllabusGuide(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveSyllabusGuide($request));
    }

    public function implementationSheets(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->implementationSheets($request));
    }

    public function saveImplementationSheet(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveImplementationSheet($request));
    }

    public function teacherWorkReports(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->teacherWorkReports($request));
    }

    public function saveTeacherWorkReport(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->saveTeacherWorkReport($request));
    }

    public function inspections(Request $request): Response
    {
        return $this->handle(fn (): array => $this->service()->inspections($request));
    }

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

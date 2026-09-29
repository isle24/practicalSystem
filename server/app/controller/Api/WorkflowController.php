<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\server\workflow\WorkflowDefinitionService;
use app\server\workflow\WorkflowService;
use support\Request;
use support\Response;
use Throwable;

class WorkflowController
{
    use Responds;

    public function options(Request $request): Response
    {
        return $this->handle(fn () => (new WorkflowDefinitionService())->options());
    }

    public function definitions(Request $request): Response
    {
        return $this->handle(fn () => (new WorkflowDefinitionService())->listing());
    }

    public function definition(Request $request): Response
    {
        return $this->handle(fn () => (new WorkflowDefinitionService())->detail((int) $request->input('version_id')));
    }

    public function save(Request $request): Response
    {
        return $this->handle(fn () => (new WorkflowDefinitionService())->save((array) $request->post()));
    }

    public function publish(Request $request): Response
    {
        return $this->handle(fn () => (new WorkflowDefinitionService())->publish((int) $request->input('version_id')));
    }

    public function preview(Request $request): Response
    {
        return $this->handle(fn () => (new WorkflowDefinitionService())->preview((string) $request->input('entity_type'), (int) $request->input('entity_id')));
    }

    public function start(Request $request): Response
    {
        return $this->handle(fn () => (new WorkflowService())->start((string) $request->input('entity_type'), (int) $request->input('entity_id'), ['request_id' => (string) $request->input('request_id')]));
    }

    public function review(Request $request): Response
    {
        return $this->handle(fn () => (new WorkflowService())->review((int) $request->input('instance_id'), (string) $request->input('action'), (string) $request->input('opinion', ''), $request->input('signature_id') ? (int) $request->input('signature_id') : null, (string) $request->input('revision')));
    }

    public function cancel(Request $request): Response
    {
        return $this->handle(function () use ($request): array {
            (new WorkflowService())->cancel((string) $request->input('entity_type'), (int) $request->input('entity_id'), (string) $request->input('reason'));
            return [];
        });
    }

    public function history(Request $request): Response
    {
        return $this->handle(fn () => (new WorkflowService())->history((string) $request->input('entity_type'), (int) $request->input('entity_id')));
    }

    public function inbox(Request $request): Response
    {
        return $this->handle(fn () => (new WorkflowService())->inbox((array) $request->get()));
    }

    private function handle(callable $action): Response
    {
        try {
            return $this->ok($action());
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            $status = match (true) {
                in_array($code, [401, 403, 404, 409, 422], true) => $code,
                $code >= 40000 && $code < 50000 => intdiv($code, 100),
                $exception instanceof \InvalidArgumentException => 422,
                default => 500,
            };
            if ($status === 500) \support\Log::error('Workflow request failed', ['exception' => $exception]);
            return $this->fail($code ?: $status, $status === 500 ? '流程处理失败，请稍后重试' : $exception->getMessage(), $status);
        }
    }
}

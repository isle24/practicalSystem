<?php

namespace app\server\workflow;

use app\model\channel\WorkflowRecord as R;
use app\server\CurrentContext;
use app\server\WorkflowLock;
use RuntimeException;

class WorkflowDefinitionService
{
    public function adapter(string $type): WorkflowEntityAdapter
    {
        $class = config('workflow.adapters.' . $type);
        if (!preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $type) || !is_string($class) || !is_subclass_of($class, WorkflowEntityAdapter::class)) throw new RuntimeException('该实体尚未注册审批适配器', 422);
        return new $class();
    }

    public function options(): array
    {
        $this->manage();
        $accounts = $this->accountQuery()->orderBy('account.id')->get(['account.id', 'account.login_name', 'users.name'])->map(fn ($row) => $row->getAttributes())->all();
        return [
            'entity_types' => array_keys((array) config('workflow.adapters', [])),
            'accounts' => $accounts,
            'roles' => R::queryTable('role')->where('status', 'enabled')->whereNull('deleted_at')->get(['id', 'name', 'role_type'])->map(fn ($row) => $row->getAttributes())->all(),
            'departments' => R::queryTable('department')->whereNull('deleted_at')->where('flag', 'on')->get(['dep_id', 'dep_name'])->map(fn ($row) => $row->getAttributes())->all(),
            'channels' => ['internal', 'wechat', 'sms'],
        ];
    }

    public function listing(): array
    {
        $this->manage();
        $items = R::q('definition')->orderBy('entity_type')->orderBy('dep_id')->get()->map(fn ($row) => R::row($row))->all();
        foreach ($items as &$item) $item['versions'] = R::q('version')->where('definition_id', $item['id'])->orderByDesc('version')->get()->map(fn ($row) => R::row($row))->all();
        return ['items' => $items];
    }

    public function detail(int $versionId): array
    {
        $this->manage();
        $version = R::row(R::q('version')->find($versionId));
        if (!$version) throw new RuntimeException('流程版本不存在', 404);
        return ['version' => $version, 'nodes' => $this->nodes($versionId)];
    }

    public function save(array $input): array
    {
        $this->manage();
        $type = trim((string) ($input['entity_type'] ?? ''));
        $this->adapter($type);
        $depId = max(0, (int) ($input['dep_id'] ?? 0));
        if ($depId && !R::queryTable('department')->where('dep_id', $depId)->where('flag', 'on')->whereNull('deleted_at')->exists()) throw new RuntimeException('学院无效', 422);
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) throw new RuntimeException('流程名称无效', 422);
        $nodes = $this->normalize((array) ($input['nodes'] ?? []));
        return (new WorkflowLock())->run(WorkflowLock::key('workflow_definition', $type, $depId), function () use ($type, $depId, $name, $nodes): array {
            return R::connection()->transaction(function () use ($type, $depId, $name, $nodes): array {
                R::q('definition')->insertOrIgnore(['entity_type' => $type, 'dep_id' => $depId, 'name' => $name, 'created_by' => R::actor()]);
                $definition = R::q('definition')->where('entity_type', $type)->where('dep_id', $depId)->lockForUpdate()->first();
                $number = (int) R::q('version')->where('definition_id', $definition->id)->max('version') + 1;
                $versionId = R::q('version')->insertGetId(['definition_id' => $definition->id, 'version' => $number, 'name' => $name, 'created_by' => R::actor()]);
                foreach ($nodes as $position => $node) R::q('node')->insert(['version_id' => $versionId, 'position' => $position + 1, 'config_json' => R::json($node)]);
                return ['definition_id' => (int) $definition->id, 'version_id' => (int) $versionId, 'version' => $number, 'nodes' => $nodes];
            });
        }, 30, true);
    }

    public function publish(int $versionId): array
    {
        $this->manage();
        return R::connection()->transaction(function () use ($versionId): array {
            $version = R::q('version')->find($versionId);
            if (!$version) throw new RuntimeException('流程版本不存在', 404);
            $definition = R::q('definition')->where('id', $version->definition_id)->lockForUpdate()->first();
            $this->adapter($definition->entity_type);
            $nodes = $this->nodes($versionId);
            $departmentIds = [(int) $definition->dep_id];
            $needsEntityDepartment = array_filter($nodes, fn ($node) => ($node['selector']['type'] ?? '') === 'role' && ($node['selector']['department'] ?? 'entity') === 'entity');
            if (!$definition->dep_id && $needsEntityDepartment) {
                $departmentIds = R::queryTable('department')->where('flag', 'on')->whereNull('deleted_at')->pluck('dep_id')->map(fn ($id) => (int) $id)->all();
                if (!$departmentIds) throw new RuntimeException('没有可校验审批人的有效学院', 422);
            }
            foreach ($departmentIds as $departmentId) $this->resolve($nodes, $departmentId);
            R::q('definition')->where('id', $definition->id)->update(['current_version_id' => $versionId, 'name' => $version->name, 'updated_at' => date('Y-m-d H:i:s')]);
            return ['definition_id' => (int) $definition->id, 'version_id' => $versionId, 'nodes' => $nodes, 'validated_department_ids' => $departmentIds];
        });
    }

    public function resolveFor(string $type, int $depId): array
    {
        $this->adapter($type);
        $definition = R::q('definition')->where('entity_type', $type)->whereIn('dep_id', array_unique([0, $depId]))->orderByDesc('dep_id')->first();
        if (!$definition || !$definition->current_version_id) throw new RuntimeException('未配置并发布适用的审批流程', 422);
        return ['version_id' => (int) $definition->current_version_id, 'nodes' => $this->resolve($this->nodes((int) $definition->current_version_id), $depId)];
    }

    public function preview(string $type, int $entityId): array
    {
        R::actor();
        $adapter = $this->adapter($type);
        $entity = $adapter->load($entityId);
        $adapter->authorize('view', $entity);
        return $this->resolveFor($type, (int) ($entity['dep_id'] ?? 0));
    }

    private function nodes(int $versionId): array
    {
        return R::q('node')->where('version_id', $versionId)->orderBy('position')->get()->map(fn ($row) => R::row($row)['config_json'])->all();
    }

    private function normalize(array $nodes): array
    {
        if (!$nodes || count($nodes) > 100) throw new RuntimeException('流程需配置 1 至 100 个节点', 422);
        $result = [];
        $reviews = 0;
        foreach (array_values($nodes) as $index => $node) {
            if (!is_array($node)) throw new RuntimeException('节点格式无效', 422);
            $name = trim((string) ($node['name'] ?? ''));
            $kind = (string) ($node['kind'] ?? 'review');
            $mode = (string) ($node['mode'] ?? 'any');
            $selector = (array) ($node['selector'] ?? []);
            $selection = (string) ($selector['type'] ?? 'accounts');
            if ($name === '' || mb_strlen($name) > 120 || !in_array($kind, ['review', 'cc'], true) || !in_array($mode, ['any', 'all'], true) || !in_array($selection, ['accounts', 'role'], true)) throw new RuntimeException('节点配置无效: ' . ($index + 1), 422);
            $department = (string) ($selector['department'] ?? 'entity');
            if (!in_array($department, ['entity', 'fixed', 'school'], true)) throw new RuntimeException('节点学院范围无效', 422);
            $fixedId = max(0, (int) ($selector['dep_id'] ?? 0));
            if ($department === 'fixed' && (!$fixedId || !R::queryTable('department')->where('dep_id', $fixedId)->whereNull('deleted_at')->where('flag', 'on')->exists())) throw new RuntimeException('节点指定学院无效', 422);
            $notification = (array) ($node['notification'] ?? []);
            $channels = array_values(array_unique((array) ($notification['channels'] ?? ['internal'])));
            if (array_diff($channels, ['internal', 'wechat', 'sms'])) throw new RuntimeException('通知渠道无效', 422);
            $enabled = filter_var($notification['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $template = trim((string) ($notification['template'] ?? ''));
            if ($enabled && (!$channels || !preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/D', $template))) throw new RuntimeException('启用通知时必须配置渠道和模板', 422);
            $result[] = [
                'name' => $name, 'kind' => $kind, 'mode' => $mode,
                'signature_required' => filter_var($node['signature_required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'selector' => ['type' => $selection, 'account_ids' => array_values(array_unique(array_filter(array_map('intval', (array) ($selector['account_ids'] ?? [])), fn ($id) => $id > 0))), 'role_id' => max(0, (int) ($selector['role_id'] ?? 0)), 'department' => $department, 'dep_id' => $fixedId],
                'notification' => ['enabled' => $enabled, 'channels' => $channels, 'template' => $template],
            ];
            if ($kind === 'review') $reviews++;
        }
        if (!$reviews) throw new RuntimeException('流程至少需要一个审批节点', 422);
        return $result;
    }

    private function resolve(array $nodes, int $entityDepId): array
    {
        $nodes = $this->normalize($nodes);
        foreach ($nodes as &$node) {
            $selector = $node['selector'];
            $query = $this->accountQuery();
            if ($selector['type'] === 'accounts') {
                $query->whereIn('account.id', $selector['account_ids']);
            } else {
                if (!$selector['role_id']) throw new RuntimeException('节点未指定角色: ' . $node['name'], 422);
                $query->whereExists(function ($q) use ($selector): void {
                    $q->selectRaw('1')->from('user_role')->join('role', 'role.id', '=', 'user_role.role_id')->whereColumn('user_role.account_id', 'account.id')->where('user_role.role_id', $selector['role_id'])->whereNull('user_role.deleted_at')->where('role.status', 'enabled')->whereNull('role.deleted_at');
                });
                $depId = $selector['department'] === 'fixed' ? $selector['dep_id'] : $entityDepId;
                if ($selector['department'] !== 'school') {
                    if (!$depId) throw new RuntimeException('节点需明确学院范围: ' . $node['name'], 422);
                    $query->whereExists(function ($q) use ($selector, $depId): void {
                        $q->selectRaw('1')->from('sys_organization')->whereColumn('sys_organization.account_id', 'account.id')->where('sys_organization.role_id', $selector['role_id'])->where('sys_organization.dep_id', $depId)->where('sys_organization.disabled', 'false')->whereNull('sys_organization.deleted_at');
                    });
                }
            }
            $node['candidates'] = $query->orderBy('account.id')->get(['account.id as account_id', 'account.user_id', 'users.name'])->map(fn ($row) => $row->getAttributes())->all();
            if (!$node['candidates']) throw new RuntimeException('节点缺少有效审批人或抄送人: ' . $node['name'], 422);
            if ($selector['type'] === 'accounts' && count($node['candidates']) !== count($selector['account_ids'])) throw new RuntimeException('节点包含失效账号: ' . $node['name'], 422);
        }
        unset($node);
        return $nodes;
    }

    private function accountQuery(): mixed
    {
        return R::queryTable('account')->join('users', 'users.id', '=', 'account.user_id')->where('account.status', 'enabled')->where('users.status', 'enabled')->whereNull('account.deleted_at')->whereNull('users.deleted_at');
    }

    private function manage(): void
    {
        R::actor();
        if (!in_array(CurrentContext::roleType(), ['school_admin', 'super_admin'], true)) throw new RuntimeException('仅学校管理员可维护流程', 403);
    }
}

<?php

namespace app\server\workflow\adapters;

use app\model\channel\InternshipRecord;
use app\server\internship\InternshipService;
use app\server\workflow\WorkflowEntityAdapter;
use RuntimeException;

final class BaseApplicationAdapter implements WorkflowEntityAdapter
{
    public function load(int $entityId, bool $forUpdate = false): array
    {
        $row = $forUpdate
            ? InternshipRecord::lockActiveRowById('base_application', $entityId)
            : InternshipRecord::activeRowById('base_application', $entityId);
        if (!$row) throw new RuntimeException('基地申报不存在', 404);
        return $row->getAttributes();
    }

    public function authorize(string $operation, array $entity): void
    {
        (new InternshipService())->authorizeBaseWorkflow($operation, $entity);
    }

    public function snapshot(array $entity): array
    {
        $base = InternshipRecord::activeRowById('base', (int) $entity['base_id']);
        if (!$base) throw new RuntimeException('基地不存在', 404);
        return ['application' => $entity, 'base' => $base->getAttributes(), 'entity_version' => (string) ($entity['updated_at'] ?? '')];
    }

    public function transition(array $entity, string $status, array $context): void
    {
        InternshipRecord::updateById('base_application', (int) $entity['id'], [
            'status' => $status === 'cancelled' ? 'draft' : $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        InternshipRecord::clearReviewOpinionDraft('base_application', (int) $entity['id'], (int) $context['actor_id'], date('Y-m-d H:i:s'));
    }
}

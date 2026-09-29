<?php

namespace app\server\workflow;

interface WorkflowEntityAdapter
{
    public function load(int $entityId, bool $forUpdate = false): array;
    public function authorize(string $operation, array $entity): void;
    public function snapshot(array $entity): array;
    public function transition(array $entity, string $status, array $context): void;
}

<?php

namespace app\server\workflow;

use support\Request;

interface LegacyWorkflowAdapter
{
    public function executeLegacyWorkflow(string $operation, Request $request): array;
}

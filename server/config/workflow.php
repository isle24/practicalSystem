<?php

return [
    'adapters' => [
        'base_application' => \app\server\workflow\adapters\BaseApplicationAdapter::class,
        'base_expense' => \app\server\workflow\adapters\ExpenseAdapter::class,
    ],
    'signature_snapshot' => \app\server\signature\SignatureService::class,
];

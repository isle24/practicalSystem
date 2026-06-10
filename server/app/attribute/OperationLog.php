<?php

namespace app\attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class OperationLog
{
    public function __construct(public readonly string $name)
    {
    }
}

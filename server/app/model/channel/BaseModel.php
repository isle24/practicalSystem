<?php

namespace app\model\channel;

use app\server\CurrentContext;
use support\Model;

abstract class BaseModel extends Model
{
    public function getConnectionName()
    {
        return CurrentContext::tenantConnection();
    }
}

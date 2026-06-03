<?php

namespace app\model\system;

use support\Model;

abstract class BaseModel extends Model
{
    protected $connection = 'master';
}

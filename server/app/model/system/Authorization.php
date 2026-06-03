<?php

namespace app\model\system;

class Authorization extends BaseModel
{
    protected $table = 'authorizations';
    protected $primaryKey = 'authorization_id';
    protected $guarded = [];
}

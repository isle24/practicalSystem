<?php

namespace app\model\system;

class Database extends BaseModel
{
    protected $table = 'databases';
    protected $primaryKey = 'database_id';
    protected $guarded = [];
}

<?php

namespace app\model\channel;

class User extends BaseModel
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $guarded = [];
}

<?php

namespace app\model\system;

class TableRecord extends BaseModel
{
    protected $guarded = [];
    public $timestamps = false;

    public static function queryTable(string $table): mixed
    {
        $model = new static();
        $model->setTable($table);
        return $model->newQuery();
    }

    public static function connection(): mixed
    {
        return (new static())->getConnection();
    }
}

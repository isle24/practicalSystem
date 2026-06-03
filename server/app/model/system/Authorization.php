<?php

namespace app\model\system;

class Authorization extends BaseModel
{
    protected $table = 'authorizations';
    protected $primaryKey = 'authorization_id';
    protected $guarded = [];

    public static function enabledSchoolDatabaseByDomain(string $domain, array $fields): ?self
    {
        return self::query()
            ->join('databases', 'authorizations.database_id', '=', 'databases.database_id')
            ->join('schools', 'authorizations.school_id', '=', 'schools.school_id')
            ->where('authorizations.authorization_domain', $domain)
            ->where('authorizations.status', 'enabled')
            ->where('databases.status', 'enabled')
            ->where('schools.status', 'enabled')
            ->first($fields);
    }

    public static function defaultBusinessDatabase(array $fields): ?self
    {
        return self::query()
            ->join('databases', 'authorizations.database_id', '=', 'databases.database_id')
            ->join('schools', 'authorizations.school_id', '=', 'schools.school_id')
            ->where('authorizations.status', 'enabled')
            ->where('databases.status', 'enabled')
            ->where('schools.status', 'enabled')
            ->where('databases.is_default_business_db', 'true')
            ->orderBy('authorizations.authorization_id')
            ->first($fields);
    }
}

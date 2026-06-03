<?php

namespace app\server\tenant;

use app\model\system\Authorization;

class TenantResolver
{
    private array $fields = [
        'authorizations.authorization_id',
        'authorizations.school_id',
        'schools.school_code',
        'schools.school_name',
        'authorizations.database_id',
        'databases.database_host',
        'databases.database_port',
        'databases.database_user',
        'databases.database_pwd',
        'databases.database_db',
        'databases.database_charset',
        'databases.database_prefix',
        'databases.is_default_business_db',
        'databases.config_version',
        'databases.updated_at',
    ];

    public function resolveByDomain(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        $record = Authorization::query()
            ->join('databases', 'authorizations.database_id', '=', 'databases.database_id')
            ->join('schools', 'authorizations.school_id', '=', 'schools.school_id')
            ->where('authorizations.authorization_domain', $domain)
            ->where('authorizations.status', 'enabled')
            ->where('databases.status', 'enabled')
            ->where('schools.status', 'enabled')
            ->first($this->fields);

        return $record ? $record->toArray() : null;
    }

    public function resolveDefaultBusinessDatabase(): ?array
    {
        $record = Authorization::query()
            ->join('databases', 'authorizations.database_id', '=', 'databases.database_id')
            ->join('schools', 'authorizations.school_id', '=', 'schools.school_id')
            ->where('authorizations.status', 'enabled')
            ->where('databases.status', 'enabled')
            ->where('schools.status', 'enabled')
            ->where('databases.is_default_business_db', 'true')
            ->orderBy('authorizations.authorization_id')
            ->first($this->fields);

        return $record ? $record->toArray() : null;
    }

    public function resolveByDomainOrDefault(string $domain): ?array
    {
        return $this->resolveByDomain($domain) ?? $this->resolveDefaultBusinessDatabase();
    }
}

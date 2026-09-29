<?php

namespace app\model\channel;

use app\server\CurrentContext;

class SignatureRecord extends TableRecord
{
    public static function signatures(): mixed
    {
        self::requireTables(['personal_signature', 'signature_session']);
        return self::queryTable('personal_signature');
    }

    public static function sessions(): mixed
    {
        self::requireTables(['personal_signature', 'signature_session']);
        return self::queryTable('signature_session');
    }

    public static function owned(?int $id = null): ?array
    {
        $query = self::signatures()->where('user_id', (int) CurrentContext::userId());
        if ($id !== null) $query->where('id', $id);
        $row = $query->orderByDesc('version')->first();
        return $row ? $row->getAttributes() : null;
    }

    public static function fileReadable(int $fileId): bool
    {
        return CurrentContext::userId() && self::signatures()->where('file_id', $fileId)->where('user_id', CurrentContext::userId())->exists();
    }

    public static function isSignatureFile(int $fileId): bool
    {
        return self::queryTable('file')->where('id', $fileId)->where('category', 'personal_signature')->exists();
    }
}

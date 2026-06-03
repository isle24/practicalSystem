<?php

namespace app\controller\Api;

use app\controller\Api\Concerns\Responds;
use app\model\channel\TableRecord as ChannelTable;
use app\server\CurrentContext;
use support\Request;
use support\Response;
use Throwable;

class LogController
{
    use Responds;

    public function list(Request $request): Response
    {
        if (!in_array('log:view', CurrentContext::permissionCodes(), true)) {
            return $this->fail(40300, '无操作权限', 403);
        }

        try {
            $page = max(1, $this->intInput($request, 'page') ?: 1);
            $pageSize = min(100, max(10, $this->intInput($request, 'page_size') ?: 20));
            $tables = $this->logTables();
            $total = 0;
            $rows = [];
            $limit = min(1000, $page * $pageSize);

            foreach ($tables as $table) {
                $query = $this->logQuery($table, $request);
                $total += (int) (clone $query)->count();
                foreach ($query->orderByDesc("{$table}.id")->forPage(1, $limit)->get(ChannelTable::operationLogColumns($table)) as $row) {
                    $rows[] = $this->row($row);
                }
            }

            usort($rows, static fn (array $left, array $right): int => strcmp((string) $right['created_at'], (string) $left['created_at']));

            return $this->ok([
                'items' => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                ],
                'tables' => $tables,
            ]);
        } catch (Throwable $exception) {
            return $this->fail(40001, $exception->getMessage(), 400);
        }
    }

    private function logTables(): array
    {
        $database = CurrentContext::schoolDatabase();
        if (!$database) {
            return [];
        }

        return ChannelTable::operationLogTables($database);
    }

    private function logQuery(string $table, Request $request): mixed
    {
        $query = ChannelTable::operationLogQuery($table);

        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $query->where(function ($builder) use ($table, $like): void {
                $builder->where("{$table}.action", 'like', $like)
                    ->orWhere("{$table}.ip", 'like', $like)
                    ->orWhere('account.login_name', 'like', $like)
                    ->orWhere('users.name', 'like', $like);
            });
        }

        foreach (['action', 'ip'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '') {
                $query->where("{$table}.{$field}", $value);
            }
        }

        $dateFrom = trim((string) $request->input('date_from', ''));
        if ($dateFrom !== '') {
            $query->where("{$table}.created_at", '>=', $dateFrom . ' 00:00:00');
        }
        $dateTo = trim((string) $request->input('date_to', ''));
        if ($dateTo !== '') {
            $query->where("{$table}.created_at", '<=', $dateTo . ' 23:59:59');
        }

        return $query;
    }

    private function row(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'source_table' => $row->source_table,
            'account_id' => $row->account_id === null ? null : (int) $row->account_id,
            'login_name' => $row->login_name,
            'user_name' => $row->user_name,
            'action' => $row->action,
            'ip' => $row->ip,
            'payload' => $this->decodePayload($row->payload),
            'created_at' => $row->created_at,
        ];
    }

    private function decodePayload(mixed $payload): mixed
    {
        if (!is_string($payload) || $payload === '') {
            return $payload;
        }

        $decoded = json_decode($payload, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $payload;
    }

    private function intInput(Request $request, string $key): int
    {
        $value = $request->input($key);
        return is_numeric($value) ? (int) $value : 0;
    }
}

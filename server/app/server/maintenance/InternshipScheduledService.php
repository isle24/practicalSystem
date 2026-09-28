<?php

namespace app\server\maintenance;

use app\model\channel\BaseVisitRecord;
use app\model\channel\InternshipScheduledRecord;
use app\model\channel\MessageRecord;
use app\server\message\MessageService;
use Throwable;

class InternshipScheduledService
{
    /**
     * 发送基地走访预约前一小时提醒。
     */
    public function remindBaseVisits(): array
    {
        if (!BaseVisitRecord::installed()) {
            return ['matched' => 0, 'sent' => 0, 'failed' => 0];
        }

        $now = time();
        $from = date('Y-m-d H:i:s', $now + 3540);
        $to = date('Y-m-d H:i:s', $now + 3660);
        $rows = BaseVisitRecord::queryTable('base_visit_plan')
            ->whereNull('deleted_at')
            ->where('status', 'scheduled')
            ->whereNotNull('visit_date')
            ->whereNotNull('start_time')
            ->whereRaw('TIMESTAMP(visit_date, start_time) BETWEEN ? AND ?', [$from, $to])
            ->get(['id', 'base_name', 'visit_date', 'start_time', 'end_time', 'contact_person', 'contact_phone', 'participant_ids', 'teacher_id', 'supervisor_id', 'contact_account_id']);

        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $visitId = (int) ($row->id ?? 0);
            if ($visitId <= 0 || MessageRecord::messageExists('base_visit_reminder', 'base_visit_plan', $visitId)) {
                continue;
            }

            $recipientIds = BaseVisitRecord::participantIds($row->toArray());
            $recipientIds[] = (int) ($row->supervisor_id ?? 0);
            $recipientIds[] = (int) ($row->contact_account_id ?? 0);
            $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds), static fn (int $id): bool => $id > 0)));
            if (!$recipientIds) {
                continue;
            }
            $accountIds = BaseVisitRecord::enabledAccountIds($recipientIds);
            if (!$accountIds) {
                continue;
            }

            try {
                (new MessageService())->sendByTemplateCode('base_visit_reminder', $accountIds, [
                    'base_name' => (string) ($row->base_name ?? '实习基地'),
                    'visit_date' => (string) ($row->visit_date ?? ''),
                    'start_time' => substr((string) ($row->start_time ?? ''), 0, 5),
                    'end_time' => substr((string) ($row->end_time ?? ''), 0, 5),
                    'contact_person' => (string) ($row->contact_person ?? ''),
                    'contact_phone' => (string) ($row->contact_phone ?? ''),
                ], [
                    'channels' => ['wechat'],
                    'entity_type' => 'base_visit_plan',
                    'entity_id' => $visitId,
                    'metadata' => ['reminder_window' => 'one_hour_before'],
                ]);
                $sent++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return ['matched' => $rows->count(), 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * 生成上一自然周实习简报并通知管理员。
     */
    public function generateWeeklyBrief(): array
    {
        $weekStart = date('Y-m-d', strtotime('monday last week'));
        $weekEnd = date('Y-m-d', strtotime('sunday last week'));
        $weekKey = date('o-\WW', strtotime($weekStart));
        $groups = [
            'school:0' => [
                'scope_type' => 'school',
                'scope_id' => 0,
                'scope_name' => '全校',
                'account_ids' => [],
            ],
        ];
        foreach (Account::internshipBriefRecipientRows() as $row) {
            $roleType = (string) ($row['role_type'] ?? '');
            $scopeType = match ($roleType) {
                'school_admin' => 'school',
                'college_admin' => 'department',
                'profession_admin' => 'profession',
                default => '',
            };
            $scopeId = match ($scopeType) {
                'department' => (int) ($row['dep_id'] ?? 0),
                'profession' => (int) ($row['profession_id'] ?? 0),
                'school' => 0,
                default => 0,
            };
            if ($scopeType === '' || ($scopeType !== 'school' && $scopeId <= 0)) {
                continue;
            }
            $scopeName = match ($scopeType) {
                'department' => (string) ($row['dep_name'] ?: '学院'),
                'profession' => (string) ($row['profession_name'] ?: '专业'),
                default => '全校',
            };
            $key = $scopeType . ':' . $scopeId;
            $groups[$key] ??= [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'scope_name' => $scopeName,
                'account_ids' => [],
            ];
            $groups[$key]['account_ids'][] = (int) ($row['account_id'] ?? 0);
        }

        $created = 0;
        $notifiedAccounts = 0;
        $briefIds = [];
        foreach ($groups as $group) {
            $brief = InternshipScheduledRecord::createWeeklyBrief(
                $weekKey,
                $weekStart,
                $weekEnd,
                (string) $group['scope_type'],
                (int) $group['scope_id'],
                (string) $group['scope_name']
            );
            $briefIds[] = (int) $brief['id'];
            $created += !empty($brief['created']) ? 1 : 0;
            $accountIds = array_values(array_unique(array_filter(array_map('intval', $group['account_ids']))));
            if (!$accountIds || MessageRecord::messageExists('internship_weekly_brief', 'internship_brief', (int) $brief['id'])) {
                continue;
            }

            (new MessageService())->sendByTemplateCode('internship_weekly_brief', $accountIds, [
                'brief_title' => (string) $brief['title'],
                'date_text' => $weekStart . ' 至 ' . $weekEnd,
                'summary_text' => (string) ($brief['summary'] ?? ''),
            ], [
                'entity_type' => 'internship_brief',
                'entity_id' => (int) $brief['id'],
            ]);
            $notifiedAccounts += count($accountIds);
        }

        return [
            'scope_count' => count($groups),
            'created' => $created,
            'notified_accounts' => $notifiedAccounts,
            'brief_ids' => $briefIds,
        ];
    }

    /**
     * 发送 7 天后到期的实习保险提醒。
     */
    public function remindExpiringInsurance(): array
    {
        $fromDate = date('Y-m-d');
        $toDate = date('Y-m-d', strtotime('+7 days'));
        $rows = InternshipScheduledRecord::expiringInsuranceRows($fromDate, $toDate);
        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $insuranceId = (int) ($row['id'] ?? 0);
            $endDate = (string) ($row['end_date'] ?? '');
            $entityType = 'insurance_expiry_' . str_replace('-', '', $endDate);
            if ($insuranceId <= 0 || MessageRecord::messageExists('internship_insurance_expiry', $entityType, $insuranceId)) {
                continue;
            }

            $accountIds = array_values(array_unique(array_filter(array_merge([
                Account::enabledAccountIdByUserRole((int) ($row['student_user_id'] ?? 0), 'student'),
                Account::enabledAccountIdByUserRole((int) ($row['teacher_user_id'] ?? 0), 'teacher'),
            ], Account::scopedAdminAccountIds(
                (int) ($row['dep_id'] ?? 0),
                (int) ($row['profession_id'] ?? 0)
            )))));
            if (!$accountIds) {
                continue;
            }

            try {
                (new MessageService())->sendByTemplateCode('internship_insurance_expiry', $accountIds, [
                    'student_name' => (string) ($row['student_name'] ?: $row['student_num'] ?: '学生'),
                    'end_date' => $endDate,
                    'policy_number' => (string) ($row['policy_number'] ?: '未填写'),
                ], [
                    'entity_type' => $entityType,
                    'entity_id' => $insuranceId,
                    'metadata' => [
                        'arrangement_id' => (int) ($row['arrangement_id'] ?? 0),
                        'end_date' => $endDate,
                    ],
                ]);
                $sent++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return ['from_date' => $fromDate, 'to_date' => $toDate, 'matched' => count($rows), 'sent' => $sent, 'failed' => $failed];
    }
}

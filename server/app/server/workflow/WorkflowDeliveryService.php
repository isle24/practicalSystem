<?php

namespace app\server\workflow;

use app\model\channel\Account;
use app\model\channel\MessageRecord;
use app\model\channel\TableRecord;
use app\model\channel\User;
use app\model\channel\UserWechat;
use app\model\channel\WorkflowRecord as R;
use app\server\config\ConfigService;
use app\server\message\MessageChannelSettingsService;
use app\server\message\MessageService;
use app\server\message\SmsMessageDriver;
use app\server\wechat\WechatMessageService;
use RuntimeException;
use Throwable;

class WorkflowDeliveryService
{
    public function run(): void
    {
        if (R::connection()->transactionLevel() !== 0) throw new RuntimeException('通知必须在提交后投递');
        $this->syncQueued();
        $this->syncStoppedSms();
        $notifications = new WorkflowNotificationService();
        foreach ($notifications->claim(30) as $item) {
            try { $this->deliver($item, $notifications); }
            catch (Throwable $exception) { $notifications->markFailed((int) $item['id'], $item['claim_token'], '通知投递暂不可用', true); }
        }
    }

    public function eligibility(array $item): array
    {
        $payload = $item['payload_json'];
        $task = R::q('task')->where('id', $payload['task_id'] ?? 0)->where('account_id', $item['recipient_id'])->first();
        $instance = R::q('instance')->where('id', $item['instance_id'])->first();
        if (!$task || !$instance || $instance->status === 'cancelled' || ($task->kind === 'review' && ($task->status !== 'pending' || $instance->status !== 'wait' || (int) $instance->active_position !== (int) $task->position)) || ($task->kind === 'cc' && $task->status !== 'notified')) return ['reason' => '流程任务已结束'];
        $config = (new MessageChannelSettingsService())->settings(true);
        if (!in_array($item['channel'], (array) $config['channels'], true)) return ['reason' => '学校已关闭此通知渠道'];
        $template = MessageRecord::templateByCode($item['template']);
        if (!$template || !in_array($item['channel'], (array) ($template['channels'] ?? ['internal']), true)) return ['reason' => '消息模板未启用此渠道'];
        $account = Account::enabledById((int) $item['recipient_id'], ['id', 'user_id']);
        $user = $account ? User::enabledById((int) $account->user_id, ['id', 'mobile', 'verified_mobile']) : null;
        if (!$user) return ['reason' => '接收账号已停用'];
        $enabled = $item['channel'] !== 'sms';
        $start = '00:00'; $end = '24:00';
        foreach (TableRecord::notifySettings((int) $item['recipient_id']) as $setting) {
            if (($setting['channel'] ?? '') === ($item['channel'] === 'internal' ? 'system' : $item['channel'])) {
                $enabled = in_array($setting['enabled'] ?? false, [true, 1, '1', 'true'], true);
            }
            if (($setting['channel'] ?? '') === 'wechat') {
                $start = (string) ($setting['quiet_start'] ?? $start); $end = (string) ($setting['quiet_end'] ?? $end);
            }
        }
        if (!$enabled) return ['reason' => '接收人已关闭此通知渠道'];
        if ($item['channel'] === 'wechat') {
            $wechat = new ConfigService();
            if (!$wechat->get('wechat.secret') || !(int) $wechat->get('wechat.agent_id')) return ['reason' => '企业微信配置不完整'];
            if (!UserWechat::isValidBinding(UserWechat::currentByUser((int) $user->id), (string) $wechat->get('wechat.corp_id'))) return ['reason' => '未绑定有效企业微信成员'];
        }
        if ($item['channel'] === 'sms') {
            if ($config['sms_url'] === '' || $config['sms_token'] === '' || $config['sms_sender'] === '') return ['reason' => '业务短信网关未配置'];
            if (!preg_match('/^1[3-9]\d{9}$/', (string) $user->mobile) || $user->mobile !== $user->verified_mobile) return ['reason' => '未绑定已验证手机号'];
        }
        return ['reason' => '', 'delay' => $item['channel'] === 'internal' ? 0 : (new WechatMessageService())->delayForSetting($start, $end), 'config' => $config, 'mobile' => (string) $user->mobile];
    }

    private function deliver(array $item, WorkflowNotificationService $notifications): void
    {
        if (!empty($item['message_id'])) {
            $previous = TableRecord::queryTable('message_channel_log')->where('message_id', $item['message_id'])->where('channel', $item['channel'])->where('account_id', $item['recipient_id'])->first();
            if ($previous && $previous->status === 'sent') { $notifications->markSent((int) $item['id'], $item['claim_token']); return; }
        }
        $eligibility = $this->eligibility($item);
        if ($eligibility['reason'] !== '') { $notifications->markSkipped((int) $item['id'], $item['claim_token'], $eligibility['reason']); return; }
        if ($eligibility['delay'] > 0) { $notifications->defer((int) $item['id'], $item['claim_token'], $eligibility['delay']); return; }
        $messageId = R::connection()->transaction(function () use ($item): int {
            $locked = R::q('outbox')->where('id', $item['id'])->where('claim_token', $item['claim_token'])->where('status', 'processing')->lockForUpdate()->first();
            if (!$locked) throw new RuntimeException('通知认领已失效');
            if ($locked->message_id) return (int) $locked->message_id;
            $payload = $item['payload_json'];
            $snapshot = $payload['snapshot'] ?? [];
            if (is_string($snapshot)) $snapshot = json_decode($snapshot, true) ?: [];
            $result = (new MessageService())->sendByTemplateCode($item['template'], [(int) $item['recipient_id']], [
                'node_name' => $payload['node_name'] ?? '流程通知', 'kind_text' => ($payload['kind'] ?? '') === 'cc' ? '抄送事项' : '审批待办',
                'entity_title' => $snapshot['name'] ?? $snapshot['title'] ?? ('事项 #' . $payload['entity_id']),
            ], ['channels' => [$item['channel']], 'exact_channels' => true, 'entity_type' => $payload['entity_type'], 'entity_id' => $payload['entity_id'], 'metadata' => ['workflow_outbox_id' => (int) $item['id'], 'dedupe_key' => $item['dedupe_key']]]);
            R::q('outbox')->where('id', $item['id'])->update(['message_id' => $result['message_id']]);
            return $result['message_id'];
        });
        if ($item['channel'] === 'internal') { $notifications->markSent((int) $item['id'], $item['claim_token']); return; }
        if ($item['channel'] === 'wechat') { $notifications->markQueued((int) $item['id'], $item['claim_token']); return; }
        $eligibility = $this->eligibility($item);
        if ($eligibility['reason'] !== '') { $this->smsLog($messageId, 'skipped', $eligibility['reason']); $notifications->markSkipped((int) $item['id'], $item['claim_token'], $eligibility['reason']); return; }
        if ($eligibility['delay'] > 0) { $notifications->defer((int) $item['id'], $item['claim_token'], $eligibility['delay']); return; }
        $log = TableRecord::queryTable('message_channel_log')->where('message_id', $messageId)->where('channel', 'sms')->first();
        if ($log && $log->status === 'sent') { $notifications->markSent((int) $item['id'], $item['claim_token']); return; }
        $message = MessageRecord::messageById($messageId);
        try {
            (new SmsMessageDriver())->send($eligibility['config'], $eligibility['mobile'], $message['title'] . "\n" . $message['content'], hash('sha256', \app\server\CurrentContext::schoolDatabaseId() . ':' . $item['dedupe_key']));
            $this->smsLog($messageId, 'sent');
            $notifications->markSent((int) $item['id'], $item['claim_token']);
        } catch (Throwable $exception) {
            $this->smsLog($messageId, (int) $item['attempts'] >= 10 ? 'failed' : 'pending', '业务短信网关未确认投递');
            throw $exception;
        }
    }

    private function smsLog(int $messageId, string $status, ?string $reason = null): void
    {
        TableRecord::queryTable('message_channel_log')->where('message_id', $messageId)->where('channel', 'sms')->update(['status' => $status, 'error_message' => $reason, 'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function syncStoppedSms(): void
    {
        $rows = R::q('outbox')->where('channel', 'sms')->whereIn('status', ['failed', 'skipped'])->whereNotNull('message_id')->whereExists(function ($query): void {
            $query->selectRaw('1')->from('message_channel_log as delivery_log')->whereColumn('delivery_log.message_id', 'workflow_outbox.message_id')->where('delivery_log.channel', 'sms')->where('delivery_log.status', 'pending');
        })->limit(200)->get();
        foreach ($rows as $row) $this->smsLog((int) $row->message_id, $row->status, $row->last_error);
    }

    private function syncQueued(): void
    {
        foreach (R::q('outbox')->where('status', 'queued')->whereExists(function ($query): void { $query->selectRaw('1')->from('message_channel_log as delivery_log')->whereColumn('delivery_log.message_id', 'workflow_outbox.message_id')->whereIn('delivery_log.status', ['sent', 'failed', 'skipped']); })->orderBy('id')->limit(200)->get() as $item) {
            $log = TableRecord::queryTable('message_channel_log')->where('message_id', $item->message_id)->where('account_id', $item->recipient_id)->where('channel', $item->channel)->first();
            if (!$log || !in_array($log->status, ['sent', 'failed', 'skipped'], true)) continue;
            R::q('outbox')->where('id', $item->id)->where('status', 'queued')->update(['status' => $log->status, 'last_error' => $log->error_message, 'sent_at' => $log->sent_at, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }
}

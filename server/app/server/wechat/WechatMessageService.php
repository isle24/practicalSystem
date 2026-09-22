<?php

namespace app\server\wechat;

use app\model\channel\Account;
use app\model\channel\MessageRecord;
use app\model\channel\TableRecord;
use app\model\channel\User;
use app\model\channel\UserWechat;
use app\model\channel\WechatMessageRecord;
use app\server\config\ConfigService;
use app\server\CurrentContext;
use RuntimeException;
use support\Redis;
use Throwable;

class WechatMessageService
{
    private WechatClient $client;

    public function __construct(?WechatClient $client = null)
    {
        $this->client = $client ?? new WechatClient();
    }

    public function send(int $logId): void
    {
        $claim = WechatMessageRecord::claim($logId);
        if (!$claim) return;
        try {
            $message = MessageRecord::messageById((int) $claim['message_id']);
            $account = Account::enabledById((int) $claim['account_id'], ['id', 'user_id']);
            if (!$message || !$account || !User::enabledById((int) $account->user_id, ['id'])) {
                WechatMessageRecord::finish($claim, 'skipped', '消息或接收人已停用');
                return;
            }
            $binding = UserWechat::currentByUser((int) $account->user_id);
            $recipient = trim((string) ($binding['wechat_userid'] ?? ''));
            if ($recipient === '' || $recipient === '@all' || str_contains($recipient, '|')) {
                WechatMessageRecord::finish($claim, 'skipped', '未绑定有效企业微信成员');
                return;
            }
            $setting = $this->setting((int) $account->id);
            if (!$setting['enabled']) {
                WechatMessageRecord::finish($claim, 'skipped', '企业微信通知已关闭');
                return;
            }
            $delay = $this->delayForSetting($setting['quiet_start'], $setting['quiet_end']);
            if ($delay > 0) {
                WechatMessageRecord::finish($claim, 'pending', null, $delay);
                return;
            }
            $config = new ConfigService();
            $corpId = trim((string) ($config->get('wechat.corp_id') ?? ''));
            $secret = trim((string) ($config->get('wechat.secret') ?? ''));
            $agentId = trim((string) ($config->get('wechat.agent_id') ?? ''));
            if ($corpId === '' || $secret === '' || !ctype_digit($agentId) || (int) $agentId <= 0) {
                WechatMessageRecord::finish($claim, 'pending', '企业微信配置不完整', 300);
                return;
            }
            if ((string) ($binding['corp_id'] ?? '') !== $corpId) {
                WechatMessageRecord::finish($claim, 'skipped', '企业微信绑定所属企业已变更，请重新绑定');
                return;
            }
            $content = trim((string) $message['title']) . "\n" . trim((string) $message['content']);
            $link = trim((string) ($message['link_url'] ?? ''));
            if ($link !== '') {
                $validLink = filter_var($link, FILTER_VALIDATE_URL)
                    && in_array(strtolower((string) parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true);
                $content .= "\n" . ($validLink ? $link : '完整内容请查看站内消息');
            }
            if (strlen($content) > 2048) $content = mb_strcut($content, 0, 1990, 'UTF-8') . "\n完整内容请查看站内消息";
            $tokenKey = 'wechat:access-token:' . (int) CurrentContext::schoolDatabaseId() . ':' . hash('sha256', $corpId . "\0" . $secret . "\0" . $agentId);
            $response = $this->client->sendTextMessage($this->accessToken($tokenKey, $corpId, $secret), $recipient, $content, $agentId);
            if (in_array((int) ($response['errcode'] ?? -1), [40014, 42001, 40001], true)) {
                Redis::del($tokenKey);
                $response = $this->client->sendTextMessage($this->accessToken($tokenKey, $corpId, $secret), $recipient, $content, $agentId);
            }
            $code = (int) ($response['errcode'] ?? -1);
            if ($code !== 0) {
                $permanent = in_array($code, [40003, 40013, 40058, 41009, 45002, 60111, 81013], true);
                WechatMessageRecord::finish($claim, $permanent ? 'failed' : 'pending', '企业微信发送错误码：' . $code, $this->retryDelay($claim));
                return;
            }
            if (!empty($response['invaliduser']) || !empty($response['invalidparty']) || !empty($response['invalidtag']) || !empty($response['unlicenseduser'])) {
                WechatMessageRecord::finish($claim, 'failed', '企业微信接收成员无效或无应用许可');
                return;
            }
            WechatMessageRecord::finish($claim, 'sent');
        } catch (Throwable) {
            WechatMessageRecord::finish($claim, 'pending', '企业微信投递暂不可用，等待重试', $this->retryDelay($claim));
        }
    }

    private function retryDelay(array $claim): int
    {
        return min(3600, 30 * (2 ** min(7, max(0, (int) $claim['attempts'] - 1))));
    }

    private function accessToken(string $key, string $corpId, string $secret): string
    {
        $cached = (string) Redis::get($key);
        if ($cached !== '') return $cached;
        $response = $this->client->getToken($corpId, $secret);
        if ((int) ($response['errcode'] ?? -1) !== 0 || empty($response['access_token'])) throw new RuntimeException('获取企业微信访问凭证失败');
        Redis::setEx($key, max(1, (int) ($response['expires_in'] ?? 7200) - 60), (string) $response['access_token']);
        return (string) $response['access_token'];
    }

    private function setting(int $accountId): array
    {
        foreach (TableRecord::notifySettings($accountId) as $row) {
            if (($row['channel'] ?? '') !== 'wechat') continue;
            return [
                'enabled' => in_array($row['enabled'] ?? true, [true, 1, '1', 'true'], true),
                'quiet_start' => (string) ($row['quiet_start'] ?? '00:00'),
                'quiet_end' => (string) ($row['quiet_end'] ?? '24:00'),
            ];
        }
        return ['enabled' => true, 'quiet_start' => '00:00', 'quiet_end' => '24:00'];
    }

    private function delayForSetting(string $start, string $end): int
    {
        $now = time();
        $minutes = (int) date('G', $now) * 60 + (int) date('i', $now);
        $startMinutes = $this->minutes($start, 0);
        $endMinutes = $this->minutes($end, 1440);
        if ($startMinutes === $endMinutes) return 0;
        $allowed = $startMinutes < $endMinutes
            ? ($minutes >= $startMinutes && $minutes < $endMinutes)
            : ($minutes >= $startMinutes || $minutes < $endMinutes);
        if ($allowed) return 0;
        $next = strtotime(date('Y-m-d', $now) . sprintf(' %02d:%02d:00', intdiv($startMinutes, 60), $startMinutes % 60));
        if ($next <= $now) $next = strtotime('+1 day', $next);
        return max(1, $next - $now);
    }

    private function minutes(string $value, int $fallback): int
    {
        if ($value === '24:00' && $fallback === 1440) return 1440;
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)
            ? (int) substr($value, 0, 2) * 60 + (int) substr($value, 3, 2) : $fallback;
    }
}

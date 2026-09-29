<?php

namespace app\server\message;

use app\model\channel\TableRecord;
use app\server\config\ConfigService;
use InvalidArgumentException;

class MessageChannelSettingsService
{
    public function settings(bool $secrets = false): array
    {
        $config = new ConfigService();
        $token = (string) ($config->get('workflow_message.sms_token') ?? '');
        return [
            'channels' => $config->get('workflow_message.channels') ?? ['internal'],
            'sms_url' => (string) ($config->get('workflow_message.sms_url') ?? ''),
            'sms_token' => $secrets ? $token : '',
            'sms_token_configured' => $token !== '',
            'sms_sender' => (string) ($config->get('workflow_message.sms_sender') ?? ''),
        ];
    }

    public function save(array $input): array
    {
        $channels = $input['channels'] ?? [];
        if (!is_array($channels) || !array_is_list($channels) || count(array_filter($channels, static fn ($channel): bool => is_string($channel) && in_array($channel, ['internal', 'wechat', 'sms'], true))) !== count($channels)) throw new InvalidArgumentException('通知渠道无效');
        $current = $this->settings(true);
        $url = trim((string) ($input['sms_url'] ?? ''));
        $sender = trim((string) ($input['sms_sender'] ?? ''));
        $token = trim((string) ($input['sms_token'] ?? ''));
        if ($token === '') $token = $current['sms_token'];
        if (!empty($input['clear_sms_token'])) $token = '';
        if ($url !== '') SmsMessageDriver::validateUrl($url);
        if (strlen($sender) > 80 || strlen($token) > 2048 || preg_match('/[\r\n]/', $token)) throw new InvalidArgumentException('短信签名或凭据格式无效');
        if (in_array('sms', $channels, true) && ($url === '' || $sender === '' || $token === '')) throw new InvalidArgumentException('请先完整配置业务短信网关');
        $config = new ConfigService();
        if (in_array('wechat', $channels, true) && (!$config->get('wechat.corp_id') || !$config->get('wechat.secret') || !(int) $config->get('wechat.agent_id'))) throw new InvalidArgumentException('请先完整配置企业微信');
        TableRecord::connection()->transaction(function () use ($config, $channels, $url, $token, $sender): void {
            foreach (['channels' => array_values(array_unique($channels)), 'sms_url' => $url, 'sms_token' => $token, 'sms_sender' => $sender] as $key => $value) $config->set('workflow_message', $key, $value);
        });
        return $this->settings();
    }
}

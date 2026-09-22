<?php

namespace app\server\wechat;

use app\server\config\ConfigService;
use DOMDocument;
use DOMXPath;
use EasyWeChat\Kernel\Encryptor;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class WechatCallbackService
{
    public const MAX_BODY_BYTES = 1048576;

    private readonly Encryptor $crypt;
    private readonly string $corpId;
    private readonly string $agentId;

    public function __construct()
    {
        $config = new ConfigService();
        $this->corpId = trim((string) $config->get('wechat.corp_id'));
        $this->agentId = trim((string) $config->get('wechat.agent_id'));
        $token = trim((string) $config->get('wechat.token'));
        $aesKey = trim((string) $config->get('wechat.encoding_aes_key'));
        if ($this->corpId === '' || $token === '' || !preg_match('/^[A-Za-z0-9+\/]{43}$/D', $aesKey)
            || strlen(base64_decode($aesKey . '=', true) ?: '') !== 32) {
            throw new RuntimeException('企业微信回调配置不完整或无效', 503);
        }

        $this->crypt = new Encryptor($this->corpId, $token, $aesKey, $this->corpId);
    }

    public function verifyUrl(string $signature, string $timestamp, string $nonce, string $echo): string
    {
        $this->assertSize($echo);
        return $this->decrypt($signature, $timestamp, $nonce, $echo);
    }

    public function decryptMessage(string $signature, string $timestamp, string $nonce, string $body): string
    {
        $envelope = $this->parseXml($body);
        $encrypted = $this->field($envelope, 'Encrypt', true);
        $plaintext = $this->decrypt($signature, $timestamp, $nonce, $encrypted);
        $message = $this->parseXml($plaintext);

        if (!hash_equals($this->corpId, $this->field($envelope, 'ToUserName', true))
            || !hash_equals($this->corpId, $this->field($message, 'ToUserName', true))) {
            throw new RuntimeException('企业微信回调校验失败', 403);
        }

        foreach ([$envelope, $message] as $xml) {
            $agentId = $this->field($xml, 'AgentID');
            if ($this->agentId !== '' && $agentId !== '' && !hash_equals($this->agentId, $agentId)) {
                throw new RuntimeException('企业微信回调校验失败', 403);
            }
        }

        return $plaintext;
    }

    private function decrypt(string $signature, string $timestamp, string $nonce, string $encrypted): string
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $signature)
            || !preg_match('/^[0-9]{1,20}$/D', $timestamp)
            || $nonce === '' || strlen($nonce) > 256 || $encrypted === '') {
            throw new InvalidArgumentException('企业微信回调参数无效');
        }

        try {
            return $this->crypt->decrypt($encrypted, $signature, $nonce, $timestamp);
        } catch (Throwable $exception) {
            throw new RuntimeException('企业微信回调校验失败', 403, $exception);
        }
    }

    private function assertSize(string $body): void
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new RuntimeException('企业微信回调请求过大', 413);
        }
    }

    private function parseXml(string $body): DOMDocument
    {
        $this->assertSize($body);
        if ($body === '' || str_contains($body, "\0") || stripos($body, '<!DOCTYPE') !== false) {
            throw new InvalidArgumentException('企业微信回调 XML 无效');
        }

        $xml = new DOMDocument();
        $xml->resolveExternals = false;
        $xml->substituteEntities = false;
        if (!$xml->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
            || $xml->doctype !== null || $xml->documentElement?->tagName !== 'xml'
            || $xml->documentElement->namespaceURI) {
            throw new InvalidArgumentException('企业微信回调 XML 无效');
        }

        return $xml;
    }

    private function field(DOMDocument $xml, string $name, bool $required = false): string
    {
        $nodes = (new DOMXPath($xml))->query('/xml/' . $name);
        if ($nodes === false || $nodes->length > 1 || ($required && $nodes->length !== 1)) {
            throw new InvalidArgumentException('企业微信回调 XML 字段无效');
        }

        $node = $nodes->item(0);
        if ($node !== null && $node->childElementCount > 0) {
            throw new InvalidArgumentException('企业微信回调 XML 字段无效');
        }
        $value = $node?->textContent ?? '';
        if ($required && $value === '') {
            throw new InvalidArgumentException('企业微信回调 XML 字段无效');
        }
        return $value;
    }
}

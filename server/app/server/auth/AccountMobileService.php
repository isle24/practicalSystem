<?php

namespace app\server\auth;

use app\model\channel\User;
use app\server\CurrentContext;
use app\server\security\SmsCodeService;
use RuntimeException;

/** 当前用户手机号验证及绑定。 */
class AccountMobileService
{
    /** 发送当前用户的绑定验证码。 */
    public function send(string $mobile): array
    {
        return (new SmsCodeService())->send($this->scene(), $mobile);
    }

    /** 验证手机号并保存可信绑定。 */
    public function verify(string $mobile, string $code): void
    {
        $scene = $this->scene();
        $sms = new SmsCodeService();
        $mobile = $sms->mobile($mobile);
        if (!$sms->verify($scene, $mobile, $code)) {
            throw new RuntimeException('验证码错误或已过期', 400);
        }
        User::connection()->transaction(function () use ($mobile): void {
            $id = (int) CurrentContext::userId();
            if (!User::lockProfile($id)) {
                throw new RuntimeException('用户不存在或已停用', 403);
            }
            User::bindVerifiedMobile($id, $mobile, date('Y-m-d H:i:s'));
        });
    }

    /** 隔离学校和用户的验证码用途。 */
    private function scene(): string
    {
        if (!CurrentContext::accountId() || !CurrentContext::userId()) {
            throw new RuntimeException('请先登录', 401);
        }
        return 'account_mobile:' . CurrentContext::schoolDatabaseId() . ':' . CurrentContext::userId();
    }
}

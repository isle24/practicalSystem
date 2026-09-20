<?php

namespace app\queue\redis;

use app\model\channel\AssistantRecord;
use app\server\message\AssistantConfiguration;
use app\server\message\AssistantTransport;
use app\server\school\SchoolConnectionManager;
use support\Context;
use Webman\RedisQueue\Consumer;
use Throwable;

/** 异步处理已授权的个人问答。 */
class AssistantAnswerConsumer implements Consumer
{
    public string $queue = 'assistant-answer';
    public string $connection = 'default';

    /** 只发送个人会话文本，不附加业务库资料。 */
    public function consume($data): void
    {
        Context::reset();
        try {
            (new SchoolConnectionManager())->bootstrapById((int) ($data['database_id'] ?? 0));
            $id = (int) ($data['turn_id'] ?? 0);
            if (!AssistantRecord::claim($id)) return;
            try {
                $turn = AssistantRecord::turn($id);
                $provider = (new AssistantConfiguration())->resolve($turn['provider_mode'], (int) $turn['account_id'], $turn['provider_revision']);
                $messages = [['role' => 'system', 'content' => '你是学校实践教学系统的问答助手。只根据用户提供的内容回答，不声称访问了学校档案、审批记录或成绩。你不能执行审批、修改数据或运行代码。无法确认的学校规定请建议用户向教师核实。']];
                $answer = (new AssistantTransport())->answer($provider['endpoint'], $provider['api_key'], $provider['model'], [...$messages, ...AssistantRecord::history($turn)]);
                AssistantRecord::finish($id, $answer);
            } catch (Throwable $error) {
                AssistantRecord::finish($id, '', in_array($error->getCode(), [400, 409], true)
                    ? $error->getMessage() : '所选服务暂不可用，请检查配置或稍后重试，未切换其他服务');
            }
        } finally { Context::reset(); }
    }
}

<?php

namespace app\queue\redis;

use app\model\channel\AssistantRecord;
use app\server\config\ConfigService;
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
                $config = new ConfigService();
                if (!$config->get('assistant.enabled')) throw new \RuntimeException('助手已停用');
                $turn = AssistantRecord::turn($id);
                $messages = [['role' => 'system', 'content' => '你是学校实践教学系统的问答助手。只根据用户提供的内容回答，不声称访问了学校档案、审批记录或成绩。你不能执行审批、修改数据或运行代码。无法确认的学校规定请建议用户向教师核实。']];
                $answer = (new AssistantTransport())->answer((string) $config->get('assistant.endpoint'), (string) $config->get('assistant.api_key'), (string) $config->get('assistant.model'), [...$messages, ...AssistantRecord::history($turn)]);
                AssistantRecord::finish($id, $answer);
            } catch (Throwable) {
                AssistantRecord::finish($id, '', '助手服务未配置完成或暂时不可用，请联系管理员或稍后重新发送');
            }
        } finally { Context::reset(); }
    }
}

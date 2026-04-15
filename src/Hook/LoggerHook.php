<?php

namespace App\Hook;
class LoggerHook implements HookInterface
{
    public function handle(string $event, array $context): array
    {
        file_put_contents(__DIR__ . '/../../storage/agent.log', "📝[$event] " . json_encode($context, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
        // echo "📝 [日志 Hook] 事件触发: $event," . json_encode($context, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        // 这里可以写入文件、发送到日志服务等
        return $context; // 不修改上下文，仅记录
    }
}
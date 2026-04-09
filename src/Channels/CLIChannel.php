<?php

namespace App\Channels;

use App\Core\Agent;

/**
 * 命令行通道实现
 * 负责处理终端的输入和输出
 */
class CLIChannel implements ChannelInterface
{
    private Agent $agent;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
    }

    /**
     * 获取通道名称
     */
    public function getName(): string
    {
        return 'cli';
    }

    /**
     * 启动通道（这里是死循环监听终端输入）
     */
    public function receive(array $payload = []): void
    {
        echo "🤖 PHP-Nanobot 已启动 (CLI模式)\n";
        echo "输入 'exit' 退出\n\n";
        $history = [];
        while (true) {
            echo "👤 你: ";
            $input = trim(fgets(STDIN));

            if ($input === 'exit') {
                echo "👋 再见！\n";
                break;
            }
            if (empty($input)) {
                continue;
            }
            
            // 这里的 sessionId 固定为 'cli_user'，保证 CLI 下记忆连贯  loop
            $reply = $this->agent->chat('cli_user', $input,$payload);
            
            echo "🤖 AI: $reply\n\n";
        }
    }

    /**
     * 发送消息（CLI 模式下直接 echo）
     */
    public function send(string $sessionId, string $message): void
    {
        // 在 CLI 模式下，send 通常是在 chat 过程中直接输出的，
        // 但为了符合接口规范，我们可以留空或用于打印系统消息。
        echo "📢 系统: $message\n";
    }
}
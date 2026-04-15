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
    public function receive(array $conservation = []): void
    {
        // 设置正确的编码
        $this->setupEncoding();

        echo "🤖 PHP-Nanobot 已启动 (CLI模式)\n";
        echo "输入 'exit' 退出\n\n";

        // 隐藏光标
        echo "\033[?25l";

        try {
            while (true) {
    
                $input = trim(readline("\033[?25h >"));// 显示光标用于输入
                echo "\033[?25l"; // 输入完成后隐藏光标

                if ($input === 'exit') {
                    echo "\033[?25h"; // 退出前显示光标
                    echo "👋 再见！\n";
                    break;
                }
                if (empty($input)) {
                    // 显示帮助提示，避免空输入循环
                    echo "💡 提示: 输入内容开始对话, 'help' 查看命令, 'exit' 退出\n";
                    continue;
                }
                if ($input === 'help') {
                    echo "📋 可用命令:\n";
                    echo "  • help - 显示此帮助\n";
                    echo "  • exit - 退出程序\n";
                    echo "  • clear - 清屏\n";
                    echo "  • status - 显示系统状态\n";
                    echo "  • 输入任意内容开始AI对话\n\n";
                    continue;
                }
                if ($input === 'clear') {
                    echo "\033[H\033[J"; // 清屏
                    continue;
                }
                if ($input === 'status') {
                    echo "📊 系统状态: 运行中 | 模式: CLI | 输入 'help' 查看更多\n";
                    continue;
                }

                // 处理中文编码
                // $input = $this->handleChineseEncoding($input);

                // 这里的 sessionId 固定为 'cli_user'，保证 CLI 下记忆连贯  loop
                $reply = $this->agent->chat('cli_user', $input, $conservation);

                echo "🤖 : " . trim($reply) . "\n";
            }
        } finally {
            // 确保程序退出时恢复光标显示
            echo "\033[?25h";
        }
    }

    /**
     * 设置正确的编码环境
     */
    private function setupEncoding(): void
    {
        // 设置内部编码为UTF-8
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        // 设置HTTP输出编码为UTF-8
        if (function_exists('mb_http_output')) {
            mb_http_output('UTF-8');
        }

        // 尝试设置终端编码为UTF-8 (Windows)
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('chcp 65001 > nul');
        }
    }

    /**
     * 处理中文编码问题
     */
    private function handleChineseEncoding(string $input): string
    {
        // 检测输入编码
        if (function_exists('mb_detect_encoding')) {
            $encoding = mb_detect_encoding($input, ['UTF-8', 'GBK', 'GB2312', 'ASCII'], true);

            if ($encoding && $encoding !== 'UTF-8') {
                // 转换编码为UTF-8
                $input = mb_convert_encoding($input, 'UTF-8', $encoding);
            }
        }

        return $input;
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
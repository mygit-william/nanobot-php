<?php

namespace App\Tools;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ShellExecutor
{
    /**
     * 执行 Shell 命令
     * 对应 OpenClaw 的 exec 工具
     */
    public function exec(string $command, string $cwd = null): string
    {
        // 1. 安全检查 (白名单/黑名单)
        $this->securityCheck($command);

        // 2. 构建进程
        $process = new Process(explode(' ', $command));
        if ($cwd) {
            $process->setWorkingDirectory($cwd);
        }

        // 3. 执行并等待完成
        $process->run();

        // 4. 错误处理
        if (!$process->isSuccessful()) {
            return "执行错误: " . $process->getErrorOutput();
        }

        // 5. 返回标准输出
        return $process->getOutput();
    }

    private function securityCheck(string $command): void
    {
        // 简单实现：禁止 rm -rf / 等危险命令
        $dangerous = ['rm -rf /', 'mkfs', 'dd'];
        foreach ($dangerous as $d) {
            if (str_contains($command, $d)) {
                throw new \Exception("⛔ 危险命令被拦截: $command");
            }
        }
    }
}
<?php

namespace App\Tools;

/**
 * 增强的Bash工具 - 使用ShellExecutor进行安全的命令执行
 */
class Bash extends Tool
{
    private ShellExecutor $executor;

    public function __construct()
    {
        $this->name = 'bash';
        $this->desc = 'Run a shell command in the current workspace with security checks.';
        $this->executor = new ShellExecutor();

        // 配置参数schema
        $this->parameterSchemas = [
            'command' => [
                'type' => 'string',
                'description' => '要执行的bash命令 (仅允许安全命令)',
                'maxLength' => 500
            ]
        ];
    }

    /**
     * 执行bash命令（带多层安全验证）
     *
     * @param string $command 要执行的bash命令
     * @return string 命令输出或错误信息
     */
    public function execute(string $command): string
    {
        try {
            // 额外的命令长度检查
            if (strlen($command) > 500) {
                return "错误：命令过长，最大允许500个字符";
            }

            // 使用ShellExecutor执行命令
            return $this->executor->exec($command);

        } catch (\Exception $e) {
            return "错误：命令执行失败 - " . $e->getMessage();
        }
    }

    /**
     * 添加允许的命令到白名单
     */
    public function addAllowedCommand(string $command): void
    {
        $this->executor->addToWhitelist($command);
    }

    /**
     * 设置命令超时时间
     */
    public function setTimeout(int $timeout): void
    {
        $this->executor->setTimeout($timeout);
    }

    /**
     * 获取当前的安全配置
     */
    public function getSecurityConfig(): array
    {
        return $this->executor->getConfig();
    }
}

     
<?php
namespace App\Tools;
class Bash extends Tool
{
    public function __construct()
    {
        $this->name = 'bash';
        $this->desc = 'Run a shell command in the current workspace.';
    }

    /**
     * @param string $command 要执行的 bash 命令
     * @return string 命令输出
     */
    // 定义参数类型，Manager 会自动识别
    public function execute(string $command): string
    {
        $output = shell_exec($command);
        return $output ?: '';
    }
}

     
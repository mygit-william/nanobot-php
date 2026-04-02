<?php

namespace App\Skills;

use App\Tools\ShellExecutor;

class OpenClawSkill //implements SkillInterface
{
    private array $meta;
    private ShellExecutor $executor;

    public function __construct(array $meta)
    {
        $this->meta = $meta;
        $this->executor = new ShellExecutor();
    }

    public function getName(): string
    {
        return $this->meta['name'];
    }

    public function getDescription(): string
    {
        // 把 SKILL.md 的内容作为描述，让 AI 知道怎么用
        // 这里可以优化，只读取 description 字段或者正文部分
        return $this->meta['description'] ?? '执行相关任务';
    }

    /**
     * OpenClaw 的核心：它不需要预定义参数
     * 因为 AI 会根据 SKILL.md 的描述，自动生成命令
     * 所以我们只需要告诉 AI，这个工具接收一个 "command" 字符串即可
     */
    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => '根据技能描述，生成需要执行的具体 Shell 命令'
                ]
            ],
            'required' => ['command']
        ];
    }

    /**
     * 执行方法：直接执行 AI 生成的命令
     */
    public function execute(array $arguments): string
    {
        $command = $arguments['command'] ?? '';
        if (empty($command)) {
            return "错误：AI 没有生成命令";
        }

        echo "⚡ 执行命令: $command\n";
        
        // 调用底层的 Shell 执行器
        return $this->executor->exec($command);
    }
}
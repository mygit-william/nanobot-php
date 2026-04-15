<?php

namespace App\Hook;

/**
 * 开发环境权限 Hook - 宽松模式，支持特殊规则
 */
class DevelopmentPermissionHook extends PermissionCheckHook
{
    public function __construct(?string $configFile = null)
    {
        // 开发环境默认使用 auto 模式
        parent::__construct([], 'auto', $configFile);
    
        // 为开发环境添加特殊规则
        $this->rules[] = [
            'tool_name' => 'write_file',
            'params' => ['path' => '/tmp/'],
            'action' => 'allow',
            'description' => '允许写入临时目录'
        ];

        $this->rules[] = [
            'tool_name' => 'bash',
            'params' => ['command' => 'ls'],
            'action' => 'allow',
            'description' => '允许基本的 ls 命令'
        ];
    }
}
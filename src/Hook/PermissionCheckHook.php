<?php

namespace App\Hook;

use App\PermissionChecker;

/**
 * 权限检查 Hook - 在工具执行前检查权限
 */
class PermissionCheckHook implements HookInterface
{
    private array $rules;
    private string $mode; // default, plan, auto
    private array $writeTools = ['write_file', 'edit_file', 'delete_file', 'bash'];
    private array $readOnlyTools = ['read_file', 'web_search', 'web_fetch'];

    public function __construct(
        array $rules = [],
        string $mode = 'default',
        ?string $configFile = null
    ) {
        $this->rules = $rules;
        $this->mode = $mode;

        if ($configFile && file_exists($configFile)) {
            $this->loadConfig($configFile);
        }
    }

    private function loadConfig(string $configFile): void
    {
        try {
            $config = json_decode(file_get_contents($configFile), true);

            if (isset($config['write_tools'])) {
                $this->writeTools = $config['write_tools'];
            }

            if (isset($config['read_only_tools'])) {
                $this->readOnlyTools = $config['read_only_tools'];
            }

            error_log("[PermissionCheckHook] Loaded config from: {$configFile}");

        } catch (\Exception $e) {
            error_log("[PermissionCheckHook] Failed to load config: " . $e->getMessage());
        }
    }

    public function handle(string $event, array $context): array
    {
        // 只在工具执行前检查权限
        if ($event !== AgentEvent::PRE_ACTION) {
            return $context;
        }

        $tool = $context['tool'] ?? null;
        if (!$tool) {
            error_log('[PermissionCheckHook] No tool found in context');
            return $context;
        }

        // 详细记录权限检查
        error_log(\sprintf(
            '[PermissionCheckHook] Checking %s for tool %s with params: %s',
            $this->mode,
            $tool['name'],
            $tool['arguments']
        ));

        $toolName = $tool['name'];
        $toolArgs = $tool['arguments'] ?? '{}';
        $toolParams = json_decode($toolArgs, true) ?? [];

        $checkResult = $this->checkPermission($toolName, $toolArgs);

        if ($checkResult['behavior'] === 'deny') {
            error_log(\sprintf(
                '[PermissionCheckHook] DENIED: %s - %s',
                $toolName,
                $checkResult['reason']
            ));

            return [
                'decision' => 'deny',
                'reason' => $checkResult['reason'],
                'tool' => ['name' => $toolName, 'arguments' => $toolArgs],
                'timestamp' => time()
            ];
        }

        if ($checkResult['behavior'] === 'ask') {
            error_log(\sprintf(
                '[PermissionCheckHook] ASK: %s - %s',
                $toolName,
                $checkResult['reason']
            ));

            return [
                'decision' => 'ask',
                'reason' => $checkResult['reason'],
                'tool' => ['name' => $toolName, 'arguments' => $toolArgs],
                'timestamp' => time()
            ];
        }

        return $context;
    }

    private function checkPermission(string $toolName, string $toolArgs): array
    {
        // 解析工具参数用于规则匹配
        $toolParams = json_decode($toolArgs, true) ?? [];

        // 模式检查 - 根据模式确定默认行为
        switch ($this->mode) {
            case 'plan':
                // plan 模式：只允许只读工具
                if ($this->isReadOnlyTool($toolName)) {
                    return ['behavior' => 'allow', 'reason' => 'plan 模式允许只读操作'];
                } else {
                    return ['behavior' => 'deny', 'reason' => 'plan 模式禁止写操作'];
                }

            case 'auto':
                // auto 模式：只读自动通过，写操作需要确认
                if ($this->isReadOnlyTool($toolName)) {
                    return ['behavior' => 'allow', 'reason' => 'auto 模式允许只读操作'];
                }
                // 写操作继续到后续检查（可能需要用户确认）
                break;

            case 'default':
                return ['behavior' => 'allow', 'reason' => '允许所有操作'];
            default:
                // default 模式：所有操作都需要确认（除非有特殊规则）
                break;
        }

        // 自定义规则检查
        foreach ($this->rules as $rule) {
            if ($this->matches($rule, $toolName, $toolParams)) {
                return $rule['action'] === 'allow'
                    ? ['behavior' => 'allow', 'reason' => '匹配允许规则']
                    : ['behavior' => 'deny', 'reason' => '匹配拒绝规则'];
            }
        }

        // 默认行为
        return ['behavior' => 'ask', 'reason' => '需要确认'];
    }

    private function isReadOnlyTool(string $toolName): bool
    {
        return in_array($toolName, $this->readOnlyTools, true);
    }

    private function matches(array $rule, string $toolName, array $params): bool
    {
        if (isset($rule['tool_name']) && $rule['tool_name'] !== $toolName) {
            return false;
        }

        if (isset($rule['params'])) {
            foreach ($rule['params'] as $key => $expectedValue) {
                if (!array_key_exists($key, $params) || $params[$key] !== $expectedValue) {
                    return false;
                }
            }
        }

        return true;
    }
}
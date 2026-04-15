<?php

namespace App\Hook;

/**
 * 权限审计 Hook - 记录所有权限相关的操作
 */
class PermissionAuditHook implements HookInterface
{
    private string $logFile;
    private bool $enabled;

    public function __construct(?string $logFile = null, bool $enabled = true)
    {
        $this->logFile = $logFile ?? __DIR__ . '/../../storage/logs/permission_audit.log';
        $this->enabled = $enabled;

        // 确保日志目录存在
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    public function handle(string $event, array $context): array
    {
        if (!$this->enabled) {
            return $context;
        }

        // 只记录工具执行相关的事件
        if ($event === AgentEvent::PRE_ACTION || $event === AgentEvent::POST_ACTION) {
            $auditData = $this->buildAuditData($event, $context);

            $success = file_put_contents(
                $this->logFile,
                json_encode($auditData, JSON_UNESCAPED_UNICODE) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );

            if (!$success) {
                error_log("[PermissionAuditHook] Failed to write audit log to: {$this->logFile}");
            }
        }

        return $context;
    }

    private function buildAuditData(string $event, array $context): array
    {
        $tool = $context['tool'] ?? null;

        $data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'microtime' => microtime(true),
            'event' => $event,
            'tool_name' => $tool['name'] ?? 'unknown',
            'user' => $_SERVER['USER'] ?? 'system',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'localhost',
            'session_id' => $this->getSessionIdFromContext($context),
            'tool_args' => $tool['arguments'] ?? null
        ];

        // 添加决策信息
        if (isset($context['decision'])) {
            $data['decision'] = $context['decision'];
            $data['reason'] = $context['reason'] ?? '';
        }

        // 添加执行结果（POST_ACTION 时）
        if ($event === AgentEvent::POST_ACTION && isset($context['tool_execution'])) {
            $execution = $context['tool_execution'];
            $data['execution_result'] = [
                'tool_name' => $execution['tool_name'],
                'output_length' => strlen($execution['output']),
                'success' => strpos($execution['output'], '错误') !== 0 && strpos($execution['output'], '失败') !== 0
            ];
        }

        return $data;
    }

    private function getSessionIdFromContext(array $context): ?string
    {
        // 从上下文中尝试获取 session_id
        foreach ($context as $key => $value) {
            if (strpos($key, 'session') !== false && is_string($value)) {
                return $value;
            }
        }
        return null;
    }
}
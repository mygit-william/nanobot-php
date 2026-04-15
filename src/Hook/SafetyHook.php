<?php
namespace App\Hook;

class SafetyHook implements HookInterface
{
    public function handle(string $event, array $context): array
    {
        // 安全检查：只允许特定动作
        $allowedActions = ['print_message'];
        
        if ($event === AgentEvent::PRE_ACTION) {
            $actionType = $context['next_action']['type'] ?? '';
            if (!in_array($actionType, $allowedActions)) {
                echo "⚠️ [安全 Hook] 拦截到未授权动作: $actionType" . PHP_EOL;
                return ['decision' => 'deny', 'reason' => "动作 '$actionType' 不被允许"];
            }
        }
        
        return $context;
    }
}


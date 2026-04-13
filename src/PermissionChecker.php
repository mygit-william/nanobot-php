<?php
namespace App;
// 定义权限检查类
class PermissionChecker {
    private array $denyRules;
    
    private array $allowRules;
//     | 模式 | 含义 | 适合什么场景 |
// |---|---|---|
// | `default` | 未命中规则时问用户 | 日常交互 |
// | `plan` | 只允许读，不允许写 | 计划、审查、分析 |
// | `auto` | 简单安全操作自动过，危险操作再问 | 高流畅度探索 |
    private string $mode;

    // 假设这些常量已定义
    public const WRITE_TOOLS = ['write_file', 'delete_file', 'bash']; // 示例写入工具
    public const READ_ONLY_TOOLS = ['get_weather', 'get_date_time']; // 示例只读工具

    public function __construct(array $denyRules, array $allowRules, string $mode) {
        $this->denyRules = $denyRules;
        $this->allowRules = $allowRules;
        $this->mode = $mode;
    }

    /**
     * 检查工具执行权限
     * @param string $toolName 工具名称
     * @param array $toolInput 工具输入参数
     * @return array 包含 'behavior' ('allow', 'deny', 'ask') 和 'reason' 的数组
     */
    public function checkPermission(string $toolName, array $toolInput): array {
        // return ["behavior" => "allow", "reason" => "auto mode allows reads"];
        // 1. 检查拒绝规则
        foreach ($this->denyRules as $rule) {
            if ($this->matches($rule, $toolName, $toolInput)) {
                return ["behavior" => "deny", "reason" => "matched deny rule"];
            }
        }

        // 2. 根据模式检查
        if ($this->mode === "plan" && in_array($toolName, self::WRITE_TOOLS, true)) {
            return ["behavior" => "deny", "reason" => "plan mode blocks writes"];
        }
        if ($this->mode === "auto" && in_array($toolName, self::READ_ONLY_TOOLS, true)) {
            return ["behavior" => "allow", "reason" => "auto mode allows reads"];
        }

        // 3. 检查允许规则
        foreach ($this->allowRules as $rule) {
            if ($this->matches($rule, $toolName, $toolInput)) {
                return ["behavior" => "allow", "reason" => "matched allow rule"];
            }
        }

        // 4. 默认行为
        return ["behavior" => "ask", "reason" => "needs confirmation"];
    }

    /**
     * 实现规则匹配逻辑 (示例)
     * 这个函数需要根据你的具体规则格式来实现。
     * 此处仅为示例，假设规则是一个关联数组，包含 'tool_name' 和可选的 'params' 键。
     * @param array $rule 规则定义
     * @param string $toolName 工具名称
     * @param array $toolInput 工具输入参数
     * @return bool 是否匹配
     */
    private function matches(array $rule, string $toolName, array $toolInput): bool {
        // 示例匹配逻辑：检查工具名称是否完全匹配
        if (isset($rule['tool_name']) && $rule['tool_name'] !== $toolName) {
            return false;
        }

        // 示例匹配逻辑：检查输入参数是否满足条件 (如果规则中有定义)
        if (isset($rule['params'])) {
            foreach ($rule['params'] as $key => $expectedValue) {
                if (!array_key_exists($key, $toolInput) || $toolInput[$key] !== $expectedValue) {
                    return false;
                }
            }
        }

        // 如果所有检查都通过，则认为匹配
        return true;
    }
}



?>
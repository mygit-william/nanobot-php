<?php

declare(strict_types=1);

namespace App\Core;

use App\LLM\LLMInterface;
use App\Tools\Bash;
use App\Tools\EditFile;
use App\Tools\ReadFile;
use App\Tools\WriteFile;

/**
 * 智能代理类
 *
 * 负责协调整个智能系统的运作，包括：
 * - 与大语言模型交互
 * - 执行系统工具
 * - 管理对话上下文
 * - 处理技能调用
 * - 长期记忆管理
 */
class Agent
{
    private const MAX_CONVERSATION_ROUNDS = 20;
    private const MAX_LONG_TERM_MEMORIES = 50;
    private const MAX_EXECUTION_STEPS = 20;

    /**
     * 大语言模型接口实例
     */
    private LLMInterface $llm;


    /**
     * 项目根路径
     */
    private string $projectRoot;

    /**
     * 长期记忆文件路径
     */
    private string $memoryFile;

    /**
     * AGENTS文档文件路径
     */
    private string $agentsFile;

    /**
     * 工作空间目录
     */
    private string $workspaceDir;

    /**
     * 工具管理器
     */
    private ToolManager $toolManager;

    /**
     * 工具执行器
     */
    private ToolExecutor $toolExecutor;

    private array $hooks = [];

    /**
     * 添加一个 Hook
     */
    public function addHook(\App\Hook\HookInterface $hook): void
    {
        $this->hooks[] = $hook;
    }

    /**
     * 触发所有注册的 Hook
     */
    private function triggerHooks(string $event, array $context): array
    {
        foreach ($this->hooks as $hook) {
            // 调用 Hook，并更新上下文
            $context = $hook->handle($event, $context);

            // 如果 Hook 决定阻断流程，则立即返回
            if (isset($context['decision']) && $context['decision'] === 'deny') {
                echo "🛑 Hook 阻断流程: " . ($context['reason'] ?? '未知原因') . PHP_EOL;
                return $context;
            }
        }
        return $context;
    }
    private function shouldStop(array $context): bool
    {
        return isset($context['decision']) && $context['decision'] === 'deny';
    }

    public function __construct(\App\LLM\LLMInterface $llm, ?string $workspaceDir = null)
    {
        $this->llm = $llm;

        // 初始化工具管理器
        $this->toolManager = new ToolManager();

        // 1. 注册工具
        $this->toolManager->register(new ReadFile());
        $this->toolManager->register(new WriteFile());
        $this->toolManager->register(new Bash());
        $this->toolManager->register(new EditFile());
        // $this->toolExecutor = new ToolExecutor($shellExecutor, $webSearch, $webFetch);

        // 动态获取项目根路径
        $this->projectRoot = realpath(__DIR__ . '/../../');
        $this->memoryFile = $this->projectRoot . '/storage/memory/long_term_memory.json';
        $this->agentsFile = $this->projectRoot . '/storage/AGENTS.md';
        $this->workspaceDir = $workspaceDir ?? $this->projectRoot . '/workspace';

        $this->ensureStorageDirectories();

        // 添加权限相关的 Hook
        $this->addPermissionHooks();
    }

    /**
     * 添加权限相关的 Hook
     */
    private function addPermissionHooks(): void
    {
        $env = getenv('APP_ENV') ?: 'development';
        $configFilePath = "config/permissions_{$env}.json";
        echo "\n@@@@" . $configFilePath . "" . PHP_EOL;
        var_dump(file_exists(__DIR__ . '/../../' . $configFilePath), realpath(__DIR__ . '/../../' . $configFilePath));
        $configContent = file_get_contents(__DIR__ . '/../../' . $configFilePath);
        if ($configContent === false) {
            throw new \RuntimeException("无法读取权限配置文件: {$configFilePath}");
        }
        $configArray = json_decode($configContent, true);
        if ($configArray === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("权限配置文件解析失败: " . json_last_error_msg());
        }
        $configArray = json_decode($configContent, true);
        if (!is_array($configArray) || !isset($configArray['mode'])) {
            throw new \RuntimeException("权限配置文件格式错误或缺少'mode'字段: {$configFilePath}");
        }
        // 主权限检查 Hook
        $permissionHook = new \App\Hook\PermissionCheckHook([], $configArray['mode'], $configFilePath);
        $this->addHook($permissionHook);

        // 权限审计 Hook
        $auditHook = new \App\Hook\PermissionAuditHook();
        $this->addHook($auditHook);

        error_log("[Agent] Added permission hooks for environment: {$env}");
    }

    /**
     * 确保存储目录存在
     */
    private function ensureStorageDirectories(): void
    {
        $directories = [
            dirname($this->memoryFile),
            $this->projectRoot . '/storage/context',
            $this->workspaceDir
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
                throw new \RuntimeException("无法创建目录: {$dir}");
            }
        }
    }

/**
 * 核心对话循环（支持多工具调用）
 */
public function chat(string $sessionId, string $input, array &$messages = []): string
{
    $context = [];
    $messages = array_merge($messages, [['role' => 'user', 'content' => $input]]);
    $tools = $this->toolManager->getFunctionDefinitions();

    $step = 0;
    while ($step < self::MAX_EXECUTION_STEPS) {
        $step++;
        $response = $this->llm->chat($messages, $tools);
        $decodedResponse = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('LLM响应解析失败: ' . json_last_error_msg());
        }

        // 如果没有工具调用，直接返回回复
        if (empty($decodedResponse['tool'])) {
            $this->saveToLongTermMemory($sessionId, $input, $decodedResponse['reply']);
            return $decodedResponse['reply'] . "\n";
        }

        // --- 收集工具调用 ---
        // 注意：这里不再只取第一个，而是处理所有工具
        $toolCalls = $decodedResponse['tool']; // 假设这是一个数组

        // 1. 构建 Assistant 消息，包含所有的 tool_calls
        $messages[] = [
            'role' => 'assistant',
            'content' => $decodedResponse['reply'] ?? null, // 如果有思考内容可以保留
            'tool_calls' => $toolCalls
        ];

        // 2. 准备收集工具执行结果的数组
        // 这个数组的顺序必须和 toolCalls 的顺序严格对应
        $toolResponses = [];

        // 3. 遍历所有工具调用并执行
        foreach ($toolCalls as $index => $toolCall) {
            $context['tool'] = $toolCall['function'];

            // --- Hook 处理 (PRE_ACTION) ---
            // 注意：这里如果 Hook 阻断，通常会阻断整个流程。
            // 如果你需要更精细的控制（比如只阻断某个工具），需要修改 Hook 逻辑。
            $context = $this->triggerHooks(\App\Hook\AgentEvent::PRE_ACTION, $context);
            if ($this->shouldStop($context)) {
                 $toolResponses[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'], // 需要确保 toolCall 中有 id
                        'name' => $context['tool']['name'],
                        'content' => '系统拒绝执行该操作。',
                    ];
                    continue; // 跳过执行，继续下一个工具
                // 如果 Hook 决定阻断，我们可以选择跳出循环或标记该工具失败
                // 这里简单处理，直接跳出，不执行任何工具
                // 你也可以在这里构建一个“被拒绝”的响应加入 $toolResponses
                break 2; // 跳出 foreach 并跳到 while 循环末尾
            }

            // --- 用户确认逻辑 ---
            // 如果配置了需要确认
            if (isset($context['decision']) && $context['decision'] === 'ask') {
                echo "⚠️ 需要确认执行工具 {$context['tool']['name']}\n";
                echo "Allow? (y/n): ";
                $userInput = fgets(STDIN);
                if (trim($userInput) !== 'y') {
                    // 用户拒绝，构建一个错误响应给 LLM
                    $toolResponses[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'], // 需要确保 toolCall 中有 id
                        'name' => $context['tool']['name'],
                        'content' => '用户手动确认不想执行该操作。',
                    ];
                    continue; // 跳过执行，继续下一个工具
                }
            }

            // --- 执行工具 ---
            $toolName = $toolCall['function']['name'];
            $params = json_decode($toolCall['function']['arguments'], true) ?? [];

            try {
                $output = $this->toolManager->run($toolName, $params);
            } catch (\Exception $e) {
                $output = 'Error: ' . $e->getMessage();
            }

            // --- Hook 处理 (POST_ACTION) ---
            $context['tool_execution'] = [
                'tool_name' => $toolName,
                'params' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'output' => $output
            ];
            $context = $this->triggerHooks(\App\Hook\AgentEvent::POST_ACTION, $context);

            // --- 收集结果 ---
            // OpenAI 格式要求返回 tool_call_id
            $toolResponses[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'] ?? "call_$index", // 如果原始数据没有 id，我们生成一个
                'name' => $toolName,
                'content' => $output,
            ];
        }

        // --- 将所有工具结果回传给模型 ---
        // 将收集到的所有结果追加到 messages 中
        $messages = array_merge($messages, $toolResponses);

        // 注意：这里不要 break，而是继续 while 循环
        // 让模型根据工具返回的结果再次进行推理
        // 下一次循环中，$this->llm->chat 会接收到包含 tool_responses 的 messages
        // 模型可能会再次调用工具，或者最终输出结果
    }

    return "执行达到最大步骤限制，请检查逻辑。";
}


    /**
     * 获取工具定义
     */
    private function getToolsDefinition(): array
    {
        return $this->toolManager->getToolsDefinition();
    }


    /**
     * 加载会话上下文
     */
    private function loadContext(string $sessionId): array
    {
        $file = $this->projectRoot . "/storage/context/{$sessionId}.json";

        if (!file_exists($file)) {
            return [];
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);
        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * 保存会话上下文
     */
    private function saveContext(string $sessionId, string $input, string $output): void
    {
        $file = $this->projectRoot . "/storage/context/{$sessionId}.json";
        $context = $this->loadContext($sessionId);

        $context[] = ['role' => 'user', 'content' => $input];
        $context[] = ['role' => 'assistant', 'content' => $output];

        // 保持最近20轮对话
        if (\count($context) > self::MAX_CONVERSATION_ROUNDS) {
            array_shift($context);
        }

        @file_put_contents($file, json_encode($context, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 加载长期记忆
     */
    private function loadLongTermMemory(): string
    {
        if (!file_exists($this->memoryFile)) {
            return "";
        }

        $content = @file_get_contents($this->memoryFile);
        return $content !== false ? $content : "";
    }

    /**
     * 保存到长期记忆
     */
    private function saveToLongTermMemory(string $sessionId, string $input, string $output): void
    {
        // 加载现有记忆
        $memories = [];
        if (file_exists($this->memoryFile)) {
            $content = @file_get_contents($this->memoryFile);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                $memories = \is_array($decoded) ? $decoded : [];
            }
        }

        // 添加新的记忆条目
        $memories[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'session_id' => $sessionId,
            'input' => $input,
            'output' => $output
        ];

        // 保持最近50条记忆
        if (\count($memories) > self::MAX_LONG_TERM_MEMORIES) {
            $memories = \array_slice($memories, -self::MAX_LONG_TERM_MEMORIES);
        }

        // 保存到文件
        @file_put_contents($this->memoryFile, json_encode($memories, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取系统提示词
     */
    public function getSystemPrompt(): string
    {
        return "";
    }

    /**
     * 获取长期记忆内容（用于调试或其他用途）
     */
    public function getLongTermMemory(): array
    {
        if (!file_exists($this->memoryFile)) {
            return [];
        }

        $content = @file_get_contents($this->memoryFile);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);
        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * 清除长期记忆
     */
    public function clearLongTermMemory(): void
    {
        if (file_exists($this->memoryFile)) {
            @unlink($this->memoryFile);
        }
    }
}

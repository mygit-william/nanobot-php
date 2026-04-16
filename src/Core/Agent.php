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
        $configArray = json_decode($configContent, true);
        if ($configArray === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("权限配置文件解析失败: " . json_last_error_msg());
        }
        // 主权限检查 Hook
        $permissionHook = new \App\Hook\PermissionCheckHook([], $configArray['mode'], $configFilePath);
        $this->addHook($permissionHook);
        $configContent = file_get_contents($configFilePath);
        if ($configContent === false) {
            throw new \RuntimeException("无法读取权限配置文件: {$configFilePath}");
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

    public function chat(string $sessionId, string $input, array &$messages = []): string
    {
        //上下文数据，包含事件相关的信息
        $context = [];

        $messages = array_merge($messages, [['role' => 'user', 'content' => $input]]);
        $tools = $this->toolManager->getFunctionDefinitions();
        // var_dump($tools);die;

        $step = 0;
        while ($step < self::MAX_EXECUTION_STEPS) {
            $step++;

            $response = $this->llm->chat($messages, $tools);
            $decodedResponse = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('LLM响应解析失败: ' . json_last_error_msg());
            }


            if (empty($decodedResponse['tool'])) {

                $this->saveToLongTermMemory($sessionId, $input, $decodedResponse['reply']);
                return $decodedResponse['reply'] . "\n";
            }
            $messages[] = ['role' => 'assistant', 'content' => $decodedResponse['reply'], 'tool_calls' => ($decodedResponse['tool'])];
            // echo "执行工具:{$decodedResponse['tool'][0]['function']['name']},参数:{$decodedResponse['tool'][0]['function']['arguments']}\n";
            // --- 行动阶段 ---
            $context['tool'] = $decodedResponse['tool'][0]['function'];
            if (count($decodedResponse['tool']) > 1) {
                throw new \RuntimeException('当前版本仅支持单工具调用，但LLM返回了多个工具调用');
            }
            $context = $this->triggerHooks(\App\Hook\AgentEvent::PRE_ACTION, $context);
            if ($this->shouldStop($context))
                break;

            // 检查是否需要用户确认
            if (isset($context['decision']) && $context['decision'] === 'ask') {
                var_dump($context);
                echo "⚠️ 需要确认执行工具 {$context['tool']['name']},参数: " . json_encode($context['tool']['arguments'], JSON_UNESCAPED_UNICODE);

                $args = $context['tool']['arguments'] ?? '{}';
                $decodedArgs = json_decode($args, true);
                if (json_last_error() === JSON_ERROR_NONE && \is_array($decodedArgs)) {
                    echo json_encode($decodedArgs, JSON_UNESCAPED_UNICODE) . PHP_EOL;
                } else {
                    echo $args . PHP_EOL;
                }
                echo "Allow? (y/n): ";
                $userInput = fgets(STDIN);
                if (trim($userInput) !== 'y') {
                    $messages[] = [
                        'role' => 'user',
                        'content' => "[USER DENIED]: {$context['tool']['name']}"
                    ];
                    continue;
                } 
            }
            $tool = $decodedResponse['tool'][0]['function'];
            $params = json_decode($tool['arguments'], true) ?? [];
            $toolName = $tool['name'];
            $output = $this->toolManager->run($toolName, $params);// ;$this->toolExecutor->executeTool($decodedResponse['tool'][0]['function']);
            $toolExecution = [
                'tool_name' => $toolName,
                'params' => json_encode($params),
                'output' => $output
            ];
            $context['tool_execution'] = $toolExecution;
            $context = $this->triggerHooks(\App\Hook\AgentEvent::POST_ACTION, $context);
            $messages[] = [
                'role' => 'user',
                'content' => "执行工具:{$toolExecution['tool_name']},结果返回:{$toolExecution['output']}"
            ];

            // echo "\n--- {$toolExecution['tool_name']}工具执行 ---\n";
            $preview = '';//mb_substr($toolExecution['output'],0,100) ;
            // var_dump("执行工具:{$toolExecution['tool_name']},参数:{$toolExecution['params']},结果返回:{$preview}");
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

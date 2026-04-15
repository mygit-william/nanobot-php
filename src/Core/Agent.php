<?php

declare(strict_types=1);

namespace App\Core;

use App\LLM\LLMInterface;
use App\PermissionChecker;
use App\Tools\ShellExecutor;
use App\Tools\WebSearch;
use App\Tools\WebFetch;

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
     * Shell命令执行器
     */
    private ShellExecutor $executor;

    /**
     * Web搜索工具
     */
    private WebSearch $webSearch;

    /**
     * Web内容获取工具
     */
    private WebFetch $webFetch;

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
        $this->executor = new \App\Tools\ShellExecutor();

        // 动态获取项目根路径
        $this->projectRoot = realpath(__DIR__ . '/../../');
        $this->memoryFile = $this->projectRoot . '/storage/memory/long_term_memory.json';
        $this->agentsFile = $this->projectRoot . '/storage/AGENTS.md';
        $this->workspaceDir = $workspaceDir ?? $this->projectRoot . '/workspace';

        // 初始化Web工具
        $this->webSearch = new \App\Tools\WebSearch();
        $this->webFetch = new \App\Tools\WebFetch();

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
        $configFile = "config/permissions_{$env}.json";
        echo "\n@@@@". $configFile ."". PHP_EOL;
        var_dump(file_exists('../../'.$configFile),realpath(__DIR__.'/../../'.$configFile));
        $configArray= json_decode(file_get_contents(__DIR__.'/../../'.$configFile), true);
        // 主权限检查 Hook
        $permissionHook = new \App\Hook\PermissionCheckHook([], $configArray['mode'], $configFile);
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
        $tools = $this->getToolsDefinition();

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

            $toolExecution = $this->executeTool($decodedResponse['tool'][0]['function']);
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
        return [
            $this->createBashTool(),
            $this->createReadFileTool(),
            $this->createWriteFileTool(),
            $this->createEditFileTool(),
            $this->createWebSearchTool(),
            // $this->createWebFetchTool()
        ];
    }

    /**
     * 创建bash工具定义
     */
    private function createBashTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'bash',
                'description' => 'Run a shell command in the current workspace.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'command' => [
                            'type' => 'string',
                            'description' => '要执行的命令'
                        ]
                    ],
                    'required' => ['command']
                ]
            ]
        ];
    }

    /**
     * 创建读取文件工具定义
     */
    private function createReadFileTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'read_file',
                'description' => 'read a file by the path.结果将以cat -n格式返回,行号从1开始.The path must be absolute path.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string']
                    ],
                    'required' => ['path']
                ]
            ]
        ];
    }

    /**
     * 创建写入文件工具定义
     */
    private function createWriteFileTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'write_file',
                'description' => 'Write content to file.For partial edits, prefer edit_file instead.The path must be absolute path.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'content' => ['type' => 'string']
                    ],
                    'required' => ['path', 'content']
                ]
            ]
        ];
    }

    /**
     * 创建编辑文件工具定义
     */
    private function createEditFileTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'edit_file',
                'description' => 'Edit file by replacing, inserting or appending content.The path must be absolute path.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'File path to edit'],
                        'operation' => [
                            'type' => 'string',
                            'enum' => ['replace', 'insert', 'append', 'prepend'],
                            'description' => 'Edit operation type'
                        ],
                        'old_content' => ['type' => 'string', 'description' => 'Content to replace (for replace operation)'],
                        'new_content' => ['type' => 'string', 'description' => 'New content to insert'],
                        'line_number' => ['type' => 'integer', 'description' => 'Line number for insert operation (optional)']
                    ],
                    'required' => ['path', 'operation', 'new_content']
                ]
            ]
        ];
    }

    /**
     * 创建Web搜索工具定义
     */
    private function createWebSearchTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'web_search',
                'description' => 'Search the web for information using various search engines (duckduckgo).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => '搜索查询关键词'
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => '返回结果的最大数量（默认10，最大50）'
                        ],
                        'engine' => [
                            'type' => 'string',
                            'enum' => ['duckduckgo'],
                            'description' => '使用的搜索引擎（默认duckduckgo）'
                        ]
                    ],
                    'required' => ['query','engine']
                ]
            ]
        ];
    }

    /**
     * 创建Web内容获取工具定义
     */
    private function createWebFetchTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'web_fetch',
                'description' => 'Fetch content from a URL and extract text.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => '要获取内容的URL'
                        ],
                        'extract_text' => [
                            'type' => 'boolean',
                            'description' => '是否提取文本内容（默认true）'
                        ],
                        'max_length' => [
                            'type' => 'integer',
                            'description' => '最大内容长度（字节，默认1MB）'
                        ]
                    ],
                    'required' => ['url']
                ]
            ]
        ];
    }

    /**
     * 执行工具调用
     */
    private function executeTool(array $tool): array
    {
        $params = json_decode($tool['arguments'], true) ?? [];
        $toolName = $tool['name'];

        switch ($toolName) {
            case 'read_file':
                $output = $this->executeReadFile($params);
                break;
            case 'write_file':
                $output = $this->executeWriteFile($params);
                break;
            case 'edit_file':
                $output = $this->executeEditFile($params);
                break;
            case 'bash':
                $output = $this->executeBash($params);
                break;
            case 'web_search':
                $output = $this->executeWebSearch($params);
                break;
            case 'web_fetch':
                $output = $this->executeWebFetch($params);
                break;
            default:
                $output = "未知工具: {$toolName}";
        }

        return [
            'tool_name' => $toolName,
            'params' => json_encode($params),
            'output' => $output
        ];
    }

    /**
     * 执行读取文件操作
     */
    private function executeReadFile(array $params): string
    {
        $filePath = $params['path'] ?? '';

        if (!file_exists($filePath)) {
            return "错误：文件不存在 -> {$filePath}";
        }

        $lines = @file($filePath);
        if ($lines === false) {
            return "错误：无法读取文件 -> {$filePath}";
        }

        $output = '';
        foreach ($lines as $lineNumber => $line) {
            $output .= ($lineNumber + 1) . "\t" . $line;
        }

        return rtrim($output);
    }

    /**
     * 执行写入文件操作
     */
    private function executeWriteFile(array $params): string
    {
        $filePath = $params['path'] ?? '';
        $content = $params['content'] ?? '';

        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            return "错误：无法创建目录 -> {$dir}";
        }

        if (@file_put_contents($filePath, $content) === false) {
            return "错误：文件写入失败 -> {$filePath}";
        }

        $preview = substr($content, 0, 100);
        return "文件写入成功";//，内容预览: {$preview}...";
    }

    /**
     * 执行编辑文件操作
     */
    private function executeEditFile(array $params): string
    {
        $filePath = $params['path'] ?? '';
        $operation = $params['operation'] ?? '';
        $newContent = $params['new_content'] ?? '';
        $oldContent = $params['old_content'] ?? '';
        $lineNumber = $params['line_number'] ?? null;

        if (!file_exists($filePath)) {
            return "错误：文件不存在 -> {$filePath}";
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return "错误：无法读取文件内容 -> {$filePath}";
        }

        return $this->performFileEdit($filePath, $content, $operation, $newContent, $oldContent, $lineNumber);
    }

    /**
     * 执行具体的文件编辑操作
     */
    private function performFileEdit(string $filePath, string $content, string $operation, string $newContent, string $oldContent, ?int $lineNumber): string
    {
        switch ($operation) {
            case 'replace':
                return $this->performReplace($filePath, $content, $oldContent, $newContent);
            case 'append':
                return $this->performAppend($filePath, $content, $newContent);
            case 'prepend':
                return $this->performPrepend($filePath, $content, $newContent);
            case 'insert':
                return $this->performInsert($filePath, $content, $newContent, $lineNumber);
            default:
                return "错误：不支持的操作类型 -> {$operation}";
        }
    }

    /**
     * 执行替换操作
     */
    private function performReplace(string $filePath, string $content, string $oldContent, string $newContent): string
    {
        if (empty($oldContent)) {
            return "错误：replace 操作需要提供 old_content 参数";
        }

        if (strpos($content, $oldContent) === false) {
            return "错误：未找到要替换的内容";
        }

        $newFileContent = str_replace($oldContent, $newContent, $content);

        if (@file_put_contents($filePath, $newFileContent) === false) {
            return "错误：文件编辑失败";
        }

        return "文件编辑成功(替换操作)，已替换内容";
    }

    /**
     * 执行追加操作
     */
    private function performAppend(string $filePath, string $content, string $newContent): string
    {
        $newFileContent = $content . $newContent;

        if (@file_put_contents($filePath, $newFileContent) === false) {
            return "错误：文件编辑失败";
        }

        return "文件编辑成功(追加操作)，已追加内容";
    }

    /**
     * 执行前置操作
     */
    private function performPrepend(string $filePath, string $content, string $newContent): string
    {
        $newFileContent = $newContent . $content;

        if (@file_put_contents($filePath, $newFileContent) === false) {
            return "错误：文件编辑失败";
        }

        return "文件编辑成功(前置操作)，已前置内容";
    }

    /**
     * 执行插入操作
     */
    private function performInsert(string $filePath, string $content, string $newContent, ?int $lineNumber): string
    {
        if ($lineNumber === null) {
            return "错误：insert 操作需要提供 line_number 参数";
        }

        $lines = explode("\n", $content);
        $lineCount = \count($lines);

        if ($lineNumber < 1 || $lineNumber > $lineCount + 1) {
            return "错误：行号超出范围";
        }

        \array_splice($lines, $lineNumber - 1, 0, $newContent);
        $newFileContent = implode("\n", $lines);

        if (@file_put_contents($filePath, $newFileContent) === false) {
            return "错误：文件编辑失败";
        }

        return "文件编辑成功(插入操作)，已在第 {$lineNumber} 行插入内容";
    }

    /**
     * 执行bash命令
     */
    private function executeBash(array $params): string
    {
        $command = $params['command'] ?? '';

        return (string) shell_exec($command . ' 2>&1');
    }

    /**
     * 执行Web搜索操作
     */
    private function executeWebSearch(array $params): string
    {
        $query = $params['query'] ?? '';
        $limit = $params['limit'] ?? 10;
        $engine = $params['engine'] ?? 'google';

        if (empty($query)) {
            return "错误：搜索查询不能为空";
        }

        try {
            $results = $this->webSearch->search($query, $limit, $engine);
            return json_encode($results, JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            return "Web搜索失败: " . $e->getMessage();
        }
    }

    /**
     * 执行Web内容获取操作
     */
    private function executeWebFetch(array $params): string
    {
        $url = $params['url'] ?? '';
        $extractText = $params['extract_text'] ?? true;
        $maxLength = $params['max_length'] ?? 1048576; // 1MB

        if (empty($url)) {
            return "错误：URL不能为空";
        }

        try {
            $result = $this->webFetch->fetch($url, [
                'extract_text' => $extractText,
                'max_content_length' => $maxLength
            ]);
            return json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            return "Web内容获取失败: " . $e->getMessage();
        }
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

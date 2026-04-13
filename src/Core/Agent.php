<?php

declare(strict_types=1);

namespace App\Core;

use App\LLM\LLMInterface;
use App\PermissionChecker;
use App\Tools\ShellExecutor;

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

    public function __construct(LLMInterface $llm, ?string $workspaceDir = null)
    {
        $this->llm = $llm;
        $this->executor = new ShellExecutor();

        // 动态获取项目根路径
        $this->projectRoot = realpath(__DIR__ . '/../../');
        $this->memoryFile = $this->projectRoot . '/storage/memory/long_term_memory.json';
        $this->agentsFile = $this->projectRoot . '/storage/AGENTS.md';
        $this->workspaceDir = $workspaceDir ?? $this->projectRoot . '/workspace';

        $this->ensureStorageDirectories();
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
        $permissionChecker = $this->createPermissionChecker();

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
            echo "执行工具:{$decodedResponse['tool'][0]['function']['name']},参数:{$decodedResponse['tool'][0]['function']['arguments']}\n";
            $toolExecution = $this->executeTool($decodedResponse['tool'][0]['function'], $permissionChecker);

            $messages[] = [
                'role' => 'user',
                'content' => "执行工具:{$toolExecution['tool_name']},结果返回:{$toolExecution['output']}"
            ];

            echo "\n--- {$toolExecution['tool_name']}工具执行 ---\n";
            $preview ='';//mb_substr($toolExecution['output'],0,100) ;
            // var_dump("执行工具:{$toolExecution['tool_name']},参数:{$toolExecution['params']},结果返回:{$preview}");
        }

        return "执行达到最大步骤限制，请检查逻辑。";
    }

    /**
     * 创建权限检查器
     */
    private function createPermissionChecker(): PermissionChecker
    {
        return new PermissionChecker(
            [], // denyRules
            [], // allowRules
            'auto' // mode
        );
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
            $this->createEditFileTool()
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
     * 执行工具调用
     */
    private function executeTool(array $tool, PermissionChecker $permissionChecker): array
    {
        $params = json_decode($tool['arguments'], true) ?? [];
        $toolName = $tool['name'];

        switch ($toolName) {
            case 'read_file':
                $output = $this->executeReadFile($params);
                break;
            case 'write_file':
                $output = $this->executeWriteFile($params, $permissionChecker);
                break;
            case 'edit_file':
                $output = $this->executeEditFile($params, $permissionChecker);
                break;
            case 'bash':
                $output = $this->executeBash($params, $permissionChecker);
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
    private function executeWriteFile(array $params, PermissionChecker $permissionChecker): string
    {
        $filePath = $params['path'] ?? '';
        $content = $params['content'] ?? '';

        $permissionCheck = $permissionChecker->checkPermission('write_file', [
            'path' => $filePath,
            'content' => $content
        ]);

        if ($permissionCheck['behavior'] === 'deny') {
            return "权限被拒绝: {$permissionCheck['reason']}";
        }

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
    private function executeEditFile(array $params, PermissionChecker $permissionChecker): string
    {
        $filePath = $params['path'] ?? '';
        $operation = $params['operation'] ?? '';
        $newContent = $params['new_content'] ?? '';
        $oldContent = $params['old_content'] ?? '';
        $lineNumber = $params['line_number'] ?? null;

        $permissionCheck = $permissionChecker->checkPermission('edit_file', [
            'path' => $filePath,
            'operation' => $operation,
            'new_content' => $newContent,
            'old_content' => $oldContent,
            'line_number' => $lineNumber
        ]);

        if ($permissionCheck['behavior'] === 'deny') {
            return "权限被拒绝: {$permissionCheck['reason']}";
        }

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
    private function executeBash(array $params, PermissionChecker $permissionChecker): string
    {
        $command = $params['command'] ?? '';

        $permissionCheck = $permissionChecker->checkPermission('bash', [
            'command' => $command
        ]);

        if ($permissionCheck['behavior'] === 'deny') {
            return "权限被拒绝: {$permissionCheck['reason']}";
        }

        if ($permissionCheck['behavior'] === 'ask') {
            echo "  Allow? (y/n): ";
            $userInput = fgets(STDIN);
            if (trim($userInput) === 'n') {
                return "[USER DENIED]:bash";
            }
        }

        return (string)shell_exec($command . ' 2>&1');
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

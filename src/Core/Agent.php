<?php

namespace App\Core;

use App\LLM\LLMInterface;
use App\Tools\ShellExecutor;
use App\Skills\SkillManager;

class Agent
{
    private LLMInterface $llm;
    private ShellExecutor $executor; // 引入执行器
    private SkillManager $skillManager;

    public function __construct(LLMInterface $llm)
    {
        $this->llm = $llm;
        $this->executor = new ShellExecutor();
        $this->skillManager = new SkillManager();
    }

    public function chat(string $sessionId, string $input,array &$messages=[]): string
    {
        // 1. 准备上下文
        $context = $this->loadContext($sessionId);
        // 2. 构建 System Prompt
        $systemPrompt = file_get_contents(__DIR__ . "/../../storage/AGENTS.md");$systemPrompt .= $this->getSystemPrompt();
        if (!empty($longTermMemory)) {
            $systemPrompt .= "\n\n## 长期记忆\n{$longTermMemory}";
        }
        
        // 3. 组装消息
        $messages = [
            ...$messages,
            ['role' => 'user', 'content' => $input]
        ];
        // 2. 获取可用工具定义 (OpenClaw 标准)
        // 这里我们将 ShellExecutor 包装成一个 Tool 定义传给 LLM
        $tools = [
             [
                "type" => "function",
                "function" => [
                    "name" => "bash",
                    "description" => "Run a shell command in the current workspace.",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "command" => [
                                "type" => "string",
                                "description" => "要执行的命令"
                            ]
                        ],
                        "required" => ["command"]
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'write_file',
                    'description' => 'Write content to file.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                        ],
                        'required' => ['path','content']
                    ]
                ]
            ],
        ];
        // 3. 执行循环 (ReAct 模式)
        // 为了防止死循环，限制最大步骤数
        $maxSteps = 20;
        $step = 0;

        while ($step < $maxSteps) {
            $step++;
            // var_dump("第 {$step} 步，当前消息：", $messages);die
            // ;var_dump($tools);
            // 调用 LLM
            $response = $this->llm->chat($messages, $tools);
            $rawAiResponse = $response;
            $response = json_decode($response, true);
            $messages[] = ['role' => 'assistant', 'content' => $response['reply']];
            if (isset($response['tool'])&&!empty($response['tool'])) {
                $tool = $response['tool'][0]['function'];
                $params = $tool['arguments'];
                $hasToolCall = false;var_dump('parrr',$params);
                $params = json_decode($params, true);
                // foreach ($response['tool'] as $k => $v) {
                //     # code...
                // }
                switch ($tool['name']) {
                    case 'read_file':
                        $hasToolCall = true;
                        $filePath = $params['path'] ?? '';
                        $fullPath = __DIR__ . '/../backend/' . $filePath;
                        $fullPath = $filePath;
                        
                        if (file_exists($fullPath)) {
                            $toolOutput = file_get_contents($fullPath);
                        } else {
                            $toolOutput = "错误：文件不存在 -> $filePath";
                        }
                        break;

                    case 'write_file':
                        $hasToolCall = true;
                        $filePath = $params['path'] ?? '';
                        $content = $params['content'] ?? '';
                        $fullPath = __DIR__ . '/../backend/' . $filePath;

                        $fullPath = $filePath;
                        
                        //目录不存在则创建
                        $dir = dirname($fullPath);
                        if (!is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                        //判断写入是否成功,失败则返回错误信息
                        if (file_put_contents($fullPath, $content) === false) {
                            $toolOutput = "错误：文件写入失败 -> $filePath";
                        } else {
                            $toolOutput = "文件写入成功,内容预览: " . substr($content, 0, 100) . "...";
                        }
                        break;

                    case 'bash':
                        $hasToolCall = true;
                        $command = $params['command'] ?? '';
 
                        $toolOutput = shell_exec($command . ' 2>&1');
                        break;

                    default:
                        $hasToolCall = false;
                        $toolOutput = "未知工具: " . $tool['name'];
                        break;
                }
                if ($hasToolCall) {
                    $messages[] = ['role' => 'user', 'content' => '执行工具:'. $tool['name'].',结果返回:' . $toolOutput];
                    echo "\n--- 工具执行结果已加入历史 ---\n";
                    var_dump('执行工具:'. $tool['name'].',结果返回:' . $toolOutput);
                    // $toolCalls++;
                    continue;
                }
            } else {
                 // 非JSON → 普通回复，结束本轮
                $messages[] = ['role' => 'user', 'content' => $response['reply']];
                // $this->saveContext($sessionId, $input, $response['reply']);
                echo "\n[最终AI回复]\n";
                return $response['reply'] . "\n";
            }
        }

        return "执行达到最大步骤限制，请检查逻辑。";
    }


    // ... (loadContext, saveContext, loadLongTermMemory 方法保持不变) ...
    public function loadContext(string $sessionId): array
    { /* ... */
        $file = "storage/context/{$sessionId}.json";
        if (file_exists($file))
            return json_decode(file_get_contents($file), true);
        return [];
    }
    public function getSystemPrompt(){
        $sm = new SkillManager();
        $sm->getToolsDefinition();
        return ;
    }
    private function saveContext(string $sessionId, string $input, string $output): void
    { /* ... */
        $file = "storage/context/{$sessionId}.json";
        $context = $this->loadContext($sessionId);
        $context[] = ['role' => 'user', 'content' => $input];
        $context[] = ['role' => 'assistant', 'content' => $output];
        if (count($context) > 20)
            array_shift($context);
        file_put_contents($file, json_encode($context, JSON_UNESCAPED_UNICODE));
    }
    private function loadLongTermMemory(): string
    { /* ... */
        if (file_exists($this->memoryFile))
            return file_get_contents($this->memoryFile);
        return "";
    }
}
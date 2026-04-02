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

    public function chat(string $sessionId, string $input,array $messages=[]): string
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
            ['role' => 'system', 'content' => $systemPrompt],
            ...$context,
            ['role' => 'user', 'content' => $input]
        ];
        // 2. 获取可用工具定义 (OpenClaw 标准)
        // 这里我们将 ShellExecutor 包装成一个 Tool 定义传给 LLM
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'exec',
                    'description' => '执行系统 Shell 命令',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'command' => ['type' => 'string', 'description' => '要执行的 shell 命令'],
                        ],
                        'required' => ['command']
                    ]
                ]
            ]
        ];

        // 3. 执行循环 (ReAct 模式)
        // 为了防止死循环，限制最大步骤数
        $maxSteps = 20;
        $step = 0;

        while ($step < $maxSteps) {
            $step++;
            // var_dump("第 {$step} 步，当前消息：", $messages);die
            ;
            // 调用 LLM
            $response = $this->llm->chat($messages, $tools);
            $rawAiResponse = $response;
            $response = json_decode($response, true);
            
            if (isset($response['tool'])) {
                # 
                $messages[] = ['role' => 'assistant', 'content' => $rawAiResponse];
                $params = $response['params'];
                $hasToolCall = false;
                switch ($response['tool']) {
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

                    case 'terminal':
                        $hasToolCall = true;
                        $command = $params['command'] ?? '';
                        $toolOutput = shell_exec($command . ' 2>&1');
                        break;

                    default:
                        $hasToolCall = false;
                        $toolOutput = "未知工具: " . $params['tool'];
                        break;
                }
                if ($hasToolCall) {
                    $messages[] = ['role' => 'user', 'content' => '执行工具:'. $response['tool'].',结果返回:' . $toolOutput];
                    echo "\n--- 工具执行结果已加入历史 ---\n";
                    // $toolCalls++;
                    continue;
                }
            } else {
               
                if (isset($response['confidence']) && $response['confidence'] ==0) {
                    continue;
                }
                 // 非JSON → 普通回复，结束本轮
                $messages[] = ['role' => 'user', 'content' => $response['reply']];
                $this->saveContext($sessionId, $input, $response['reply']);
                echo "\n[最终AI回复]\n";
                return $response['reply'] . "\n";
                // break;

                // $copy=$messages;
                // var_dump("LLM 回复：", $response,array_pop($copy));;
                // 情况 A: LLM 直接回复文本（任务完成）
                // if (isset($response['content']) && empty($response['tool'])) {
                //     $this->saveContext($sessionId, $input, $response['content']);
                //     return $response['content'];
                // }
            }
            //TODO: 情况 B: LLM 要求调用工具
            // if (isset($response['tool'])) {
            //     $toolCall = $response['tool'];
            //     $toolName = $toolCall['function']['name'];
            //     $args = json_decode($toolCall['function']['arguments'], true);

            //     echo "🛠️ 正在执行: {$toolName} -> " . $args['command'] . "\n";

            //     // --- 核心：PHP 执行 AI 生成的命令 ---
            //     $observation = '';
            //     if ($toolName === 'exec') {
            //         try {
            //             $observation = $this->executor->exec($args['command']);
            //         } catch (\Exception $e) {
            //             $observation = $e->getMessage();
            //         }
            //     }

            //     // 将“工具调用”和“执行结果”加入上下文
            //     $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => $response['tool_calls']];
            //     $messages[] = [
            //         'role' => 'tool', 
            //         'name' => $toolName, 
            //         'content' => $observation, // 把命令执行结果喂回给 AI
            //         'tool_call_id' => $toolCall['id']
            //     ];

            //     // 继续循环，让 AI 根据执行结果决定下一步
            //     continue;
            // }
        }

        return "执行达到最大步骤限制，请检查逻辑。";
    }


    // ... (loadContext, saveContext, loadLongTermMemory 方法保持不变) ...
    private function loadContext(string $sessionId): array
    { /* ... */
        $file = "storage/context/{$sessionId}.json";
        if (file_exists($file))
            return json_decode(file_get_contents($file), true);
        return [];
    }
    private function getSystemPrompt(){
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
<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;

/**
 * 1. 定义工具接口
 */
interface ToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    // 定义参数的 JSON Schema，这是 Function Calling 的核心
    public function getParameters(): array; 
    public function run(array $arguments): string;
}

/**
 * 2. 具体工具实现
 */
class SearchTool implements ToolInterface
{
    public function getName(): string { return "google_search"; }
    
    public function getDescription(): string { 
        return "Useful for searching current information, news, or technical documentation."; 
    }

    public function getParameters(): array
    {
        return [
            "type" => "object",
            "properties" => [
                "query" => [
                    "type" => "string",
                    "description" => "The search query (e.g. 'PHP 8.3 features')"
                ]
            ],
            "required" => ["query"]
        ];
    }

    public function run(array $arguments): string
    {
        echo "🔍 [SearchTool] 正在搜索: " . $arguments['query'] . "...\n";
        sleep(1);
        return json_encode([
            "results" => ["PHP 8.3 released", "Typed class constants", "json_validate function"]
        ]);
    }
}

class CoderTool implements ToolInterface
{
    public function getName(): string { return "php_coder"; }
    
    public function getDescription(): string { 
        return "Useful for writing, debugging, or explaining PHP code."; 
    }

    public function getParameters(): array
    {
        return [
            "type" => "object",
            "properties" => [
                "code" => [
                    "type" => "string",
                    "description" => "The PHP code to generate"
                ]
            ],
            "required" => ["code"]
        ];
    }

    public function run(array $arguments): string
    {
        echo "💻 [CoderTool] 正在编写代码...\n";
        sleep(1);
        return "Code generated:\n```php\n" .  $arguments['code'] . "\n```";
    }
}

/**
 * 3. 核心编排器：Supervisor
 */
class Supervisor
{
    private Client $client;
    private string $apiKey;
    private array $tools = [];
    
    // 简化的 System Prompt，不需要教 LLM 怎么返回 JSON 了
    private string $systemPrompt = <<<EOT
You are a helpful AI Supervisor managing a team. 
Use the provided tools to answer the user's request step-by-step.
If you have all the information you need, you can reply directly without using a tool.
EOT;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            // Disable SSL verification for development (not recommended for production)
            'verify' => false,
            'curl' => [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]
        ]);
    }

    public function addTool(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * 主执行循环
     */
    public function run(string $userQuery): void
    {
        $chatHistory = [];
        
        echo "🤖 Supervisor: 接收到任务 -> $userQuery\n\n";

        $maxSteps = 5;
        $step = 0;

        while ($step < $maxSteps) {
            $step++;

            // 1. 构建请求体
            $messages = array_merge(
                [['role' => 'system', 'content' => $this->systemPrompt]],
                $chatHistory,
                [['role' => 'user', 'content' => $userQuery]]
            );

            // 2. 构建工具定义列表 (Tools Definition)
            $toolsDefinition = [];
            foreach ($this->tools as $tool) {
                $toolsDefinition[] = [
                    "type" => "function",
                    "function" => [
                        "name" => $tool->getName(),
                        "description" => $tool->getDescription(),
                        "parameters" => $tool->getParameters()
                    ]
                ];
            }

            // 3. 调用 OpenAI API (使用 tools 参数)
            try {
                $response = $this->client->post('https://api.longcat.chat/openai/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' .'ak_2ol0po6Nv7XJ9HS7Jh2He42L4de61',
                        'Content-Type' => 'application/json',
                        
                    ],
                    'json' => [
                        'model' => 'LongCat-Flash-Chat', // 或 gpt-4
                        'messages' => $messages,
                        'tools' => $toolsDefinition,
                        'tool_choice' => 'auto' // 让模型自己决定要不要调工具
                    ],
                    'verify' => false,  // Disable SSL verification
                ]);

                $responseBody = json_decode($response->getBody()->getContents(), true);
                $message = $responseBody['choices'][0]['message'];

            } catch (Exception $e) {
                echo "❌ API Error: " . $e->getMessage() . "\n";
                break;
            }
            echo "🧾 模型回复:\n" . json_encode($message, JSON_UNESCAPED_UNICODE) . "\n";
            // 4. 处理响应：检查是否有 tool_calls
            if (isset($message['tool_calls'])) {
                // 模型决定调用工具
                $toolCall = $message['tool_calls'][0]; // 这里简化处理，假设一次只调一个
                $functionName = $toolCall['function']['name'];
                $functionArgs = json_decode($toolCall['function']['arguments'], true);

                echo "🧠 思考: 模型决定调用工具 '$functionName'\n";

                if (isset($this->tools[$functionName])) {
                    // 执行工具
                    $tool = $this->tools[$functionName];
                    $observation = $tool->run($functionArgs);

                    // 将工具调用和结果加入历史记录
                    // 第一步：加入模型的调用请求
                    $chatHistory[] = $message; 
                    // 第二步：加入工具的返回结果 (role: tool)
                    $chatHistory[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content' => $observation
                    ];

                    // 更新 userQuery 为空，因为下一轮对话是基于 history 的
                    $userQuery = ""; 
                }
            } else {
                // 模型没有调用工具，直接输出了文本（任务结束）
                echo "✅ 最终回复:\n" . $message['content'] . "\n";
                break;
            }
            
            echo "-----------------------------------\n";
        }
    }
}

// ================= 运行演示 =================

$supervisor = new Supervisor('YOUR_OPENAI_API_KEY'); // <--- 填入你的 Key

$supervisor->addTool(new SearchTool());
$supervisor->addTool(new CoderTool());

$task = "帮我搜索 PHP 8.3 的新特性，然后写一个简单的类来展示 Typed Class Constants。";

$supervisor->run($task);
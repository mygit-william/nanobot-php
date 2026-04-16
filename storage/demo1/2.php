<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;

/**
 * 1. 共享黑板：用于存储任务状态和上下文
 * 这是团队协作的核心，避免上下文爆炸
 */
class Blackboard
{
    public string $originalGoal = "";
    public array $tasks = [];       // 待办任务列表
    public array $completedTasks = []; // 已完成任务结果
    public string $finalAnswer = "";
}

/**
 * 2. 基础智能体类
 */
abstract class Agent
{
    protected string $name;
    protected string $role;
    protected Client $client;
    protected string $apiKey;

    public function __construct(string $name, string $role, string $apiKey)
    {
        $this->name = $name;
        $this->role = $role;
        $this->apiKey = $apiKey;
        $this->client = new Client();
    }

    // 统一的 API 调用方法
    protected function callLLM(array $messages, array $tools = []): array
    {
        $payload = [
            'model' => 'LongCat-Flash-Chat',
            'messages' => $messages,
            'temperature' => 0.7,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->client->post('https://api.longcat.chat/openai/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'verify' => false,  // Disable SSL verification for development
             'curl' => [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]
        ]);

        return json_decode($response->getBody()->getContents(), true)['choices'][0]['message'];
    }
}

/**
 * 3. 员工智能体：只负责干活，不负责规划
 */
class WorkerAgent extends Agent
{
    private array $tools = [];

    public function addTool(array $toolDefinition, callable $function)
    {
        $this->tools[$toolDefinition['function']['name']] = [
            'definition' => $toolDefinition,
            'function' => $function
        ];
    }

    /**
     * 员工接收 CEO 的指令进行执行
     */
    public function execute(string $instruction, Blackboard $blackboard): string
    {
        echo "👷 [{$this->name}] 收到指令: $instruction\n";

        // 构建工具列表供 API 使用
        $toolsList = array_values(array_map(fn($t) => $t['definition'], $this->tools));

        $messages = [
            ['role' => 'system', 'content' => "You are {$this->role}. Follow the instruction strictly. Use tools if necessary."],
            ['role' => 'user', 'content' => $instruction]
        ];

        // 简单的执行循环（员工内部也可以有简单的工具调用循环）
        $step = 0;
        while ($step < 3) {
            $response = $this->callLLM($messages, $toolsList);

            // 如果没有工具调用，直接返回文本结果
            if (!isset($response['tool_calls'])) {
                echo "👷 [{$this->name}] 完成任务，返回结果。\n";
                return $response['content'];
            }

            // 执行工具
            $toolCall = $response['tool_calls'][0];
            $funcName = $toolCall['function']['name'];
            $args = json_decode($toolCall['function']['arguments'], true);

            echo "🛠️ [{$this->name}] 使用工具: $funcName\n";
            
            $result = ($this->tools[$funcName]['function'])($args);

            $messages[] = $response;
            $messages[] = ['role' => 'tool', 'tool_call_id' => $toolCall['id'], 'content' => $result];
            $step++;
        }
        return "执行超时或出错";
    }
}

/**
 * 4. CEO 智能体：负责编排、拆解任务、分配工作
 */
class CEOAgent extends Agent
{
    private array $workers = [];

    public function addWorker(WorkerAgent $worker)
    {
        $this->workers[$worker->name] = $worker;
    }

    /**
     * 核心编排逻辑
     */
    public function orchestrate(string $userGoal, Blackboard $blackboard): void
    {
        echo "🤵 [CEO] 开始规划任务: $userGoal\n";
        $blackboard->originalGoal = $userGoal;

        // 1. 任务拆解阶段 (Planning)
        // CEO 调用 LLM 生成任务列表（这里模拟 JSON 输出，实际也可以用 Function Calling）
        $planPrompt = <<<EOT
You are the CEO. Break down the user's goal into a list of specific tasks.
Assign each task to one of these workers: {worker_names}.
Output format: JSON array of objects with keys "task", "worker".
EOT;
        
        $workerNames = implode(', ', array_keys($this->workers));
        $planPrompt = str_replace('{worker_names}', $workerNames, $planPrompt);

        // 这里为了演示简单，直接硬编码模拟 CEO 的规划结果
        // 真实场景中，这里会请求 LLM 生成 JSON
        $tasks = [
            ['task' => '搜索 PHP 8.3 的新特性，特别是 Typed Class Constants', 'worker' => 'Searcher'],
            ['task' => '根据搜索结果编写 PHP 代码示例', 'worker' => 'Coder']
        ];

        echo "📋 [CEO] 任务拆解完成，共 " . count($tasks) . " 个步骤。\n";

        // 2. 任务分发与执行循环 (Execution Loop)
        foreach ($tasks as $index => $step) {
            $taskDesc = $step['task'];
            $workerName = $step['worker'];

            echo "👉 [CEO] 分发任务 $index: 指派给 $workerName - [$taskDesc]\n";

            if (isset($this->workers[$workerName])) {
                $worker = $this->workers[$workerName];
                
                // 员工执行任务
                $result = $worker->execute($taskDesc, $blackboard);
                
                // 记录结果到黑板
                $blackboard->completedTasks[] = [
                    'worker' => $workerName,
                    'result' => $result
                ];
            }
        }

        // 3. 最终整合 (Synthesis)
        echo "🤵 [CEO] 所有任务完成，正在整合最终报告...\n";
        $this->synthesizeReport($blackboard);
    }

    private function synthesizeReport(Blackboard $blackboard): void
    {
        // 收集所有员工的结果
        $context = "";
        foreach ($blackboard->completedTasks as $task) {
            $context .= "Worker {$task['worker']} reported: {$task['result']}\n";
        }

        $messages = [
            ['role' => 'system', 'content' => 'You are the CEO. Summarize the results for the user.'],
            ['role' => 'user', 'content' => "Original Goal: {$blackboard->originalGoal}\n\nExecution Results:\n$context"]
        ];

        $response = $this->callLLM($messages);
        echo "\n✅ [CEO] 最终报告:\n" . $response['content'] . "\n";
    }
}

// ================= 运行演示 =================

$apiKey = 'ak_2ol0po6Nv7XJ9HS7Jh2He42L4de61'; // <--- 填入 Key
$blackboard = new Blackboard();

// 1. 组建团队
$ceo = new CEOAgent('Boss', 'Project Manager', $apiKey);

$searcher = new WorkerAgent('Searcher', 'Research Expert', $apiKey);
// 给搜索员工配置工具
$searcher->addTool([
    "type" => "function",
    "function" => [
        "name" => "search_web",
        "description" => "Search the web",
        "parameters" => ["type" => "object", "properties" => ["q" => ["type" => "string"]], "required" => ["q"]]
    ]
], function($args) {
    sleep(1);
    return json_encode(["results" => "PHP 8.3 introduces Typed Class Constants (e.g. public const string NAME = 'test');"]);
});
$ceo->addWorker($searcher);

$coder = new WorkerAgent('Coder', 'Senior PHP Developer', $apiKey);
// 给代码员工配置工具
$coder->addTool([
    "type" => "function",
    "function" => [
        "name" => "write_code",
        "description" => "Write PHP code",
        "parameters" => ["type" => "object", "properties" => ["code" => ["type" => "string"]], "required" => ["code"]]
    ]
], function($args) {
    sleep(1);
    return "Code Saved: " . $args['code'];
});
$ceo->addWorker($coder);

// 2. 下达目标
$goal = "我想了解 PHP 8.3 的 Typed Constants，并写一个示例类。";

// 3. 启动编排
$ceo->orchestrate($goal, $blackboard);
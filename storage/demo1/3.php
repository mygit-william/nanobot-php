<?php

require 'vendor/autoload.php';
require __DIR__ . '/../../vendor/autoload.php';
use App\Core\Agent;
use App\LLM\LLMFactory;
use GuzzleHttp\Client;

/**
 * 1. 共享黑板：用于存储任务状态
 */
class Blackboard
{
    public string $originalGoal = "";
    public array $tasks = [];
    public array $completedTasks = [];
}

/**
 * 2. 基础智能体类
 */
abstract class BaseAgent
{
    protected string $name;
    protected Client $client;
    protected string $apiKey;

    public function __construct(string $name, string $apiKey)
    {
        $this->name = $name;
        $this->apiKey = $apiKey;
        $this->client = new Client();
    }

    /**
     * 统一的 LLM 调用方法 (支持 Tool Use)
     */
    protected function callLLM(array $messages, array $tools = []): array
    {
        $payload = [
            'model' => 'glm-4.5-air', // 建议使用 gpt-4o 或 gpt-3.5-turbo
            'messages' => $messages,
            'temperature' => 0.0, // 设置为 0 以获得最确定的逻辑输出
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto'; // 让模型自己决定
        }

        $response = $this->client->post('https://open.bigmodel.cn/api/paas/v4/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'verify' => false,  // Disable SSL verification for development

        ]);
        // var_dump($response->getBody()->getContents());
        return json_decode($response->getBody()->getContents(), true)['choices'][0]['message'];
    }
}

/**
 * 3. 员工智能体 (Worker Agent)
 * 负责具体干活
 */
class WorkerAgent extends BaseAgent
{
    private string $role;
    private array $tools = [];

    public function __construct(string $name, string $role, string $apiKey)
    {
        parent::__construct($name, $apiKey);
        $this->role = $role;
    }

    // 添加工具 (如搜索、写代码)
    public function addTool(array $toolDefinition, callable $function)
    {
        $this->tools[$toolDefinition['function']['name']] = [
            'definition' => $toolDefinition,
            'function' => $function
        ];
    }

    /**
     * 员工执行逻辑
     */
    public function execute(string $instruction, Blackboard $blackboard): string
    {
        echo "👷 [{$this->name}] 收到指令: $instruction\n";

        $toolsList = array_values(array_map(fn($t) => $t['definition'], $this->tools));

        $messages = [
            ['role' => 'system', 'content' => "You are {$this->role}. Execute the instruction using your tools."],
            ['role' => 'user', 'content' => $instruction]
        ];

        // 简单的工具调用循环
        $step = 0;
        $lastResult = "";

        while ($step < 5) {
            $response = $this->callLLM($messages, $toolsList);

            // 如果没有工具调用，说明任务完成，返回文本
            if (!isset($response['tool_calls'])) {
                $lastResult = $response['content'];
                echo "👷 [{$this->name}] 完成任务，返回结果:{$lastResult}。\n";
                break;
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

        return $lastResult;
    }
}

/**
 * 4. CEO 智能体 (Orchestrator)
 * 负责编排，它只有一个工具：create_plan
 */
class CEOAgent extends BaseAgent
{
    private array $workers = [];

    public function addWorker(WorkerAgent $worker)
    {
        $this->workers[$worker->name] = $worker;
    }

    /**
     * CEO 核心编排逻辑
     */
    public function orchestrate(string $userGoal, Blackboard $blackboard): void
    {
        echo "🤵 [CEO] 开始规划任务: $userGoal\n";
        $blackboard->originalGoal = $userGoal;

        // 1. 定义 "create_plan" 工具
        // 这是 CEO 唯一能调用的工具，用于将任务拆解
        $planningTool = [
            "type" => "function",
            "function" => [
                "name" => "create_plan",
                "description" => "Break down the user's goal into a sequence of tasks for specific workers.",
                "parameters" => [
                    "type" => "object",
                    "properties" => [
                        "steps" => [
                            "type" => "array",
                            "description" => "List of tasks to execute",
                            "items" => [
                                "type" => "object",
                                "properties" => [
                                    "worker" => [
                                        "type" => "string",
                                        "description" => "The name of the worker to assign (Available: " . implode(', ', array_keys($this->workers)) . ")"
                                    ],
                                    "task" => [
                                        "type" => "string",
                                        "description" => "The detailed instruction for the worker"
                                    ]
                                ],
                                "required" => ["worker", "task"]
                            ]
                        ]
                    ],
                    "required" => ["steps"]
                ]
            ]
        ];

        $messages = [
            ['role' => 'system', 'content' => "You are a Project Manager. Your goal is to delegate tasks to your team.please answer in Chinese not English."],
            ['role' => 'user', 'content' => $userGoal]
        ];
        var_dump($messages,$planningTool);

        // 2. 调用 LLM 进行规划
        // 注意：这里我们只传入了 $planningTool
        echo "🤵 [CEO] 正在思考并调用规划工具...\n";
        $response = $this->callLLM($messages, [$planningTool]);
        echo "🤵 [CEO] response: " . json_encode($response,JSON_UNESCAPED_UNICODE) . "\n";
        // 3. 解析工具调用
        if (!isset($response['tool_calls'])) {
            echo "❌ [CEO] 规划失败：模型没有调用 create_plan 工具。\n";
            return;
        }

        $toolCall = $response['tool_calls'][0];
        if ($toolCall['function']['name'] !== 'create_plan') {
            echo "❌ [CEO] 错误：模型调用了未知的工具。\n";
            return;
        }

        // 获取计划参数
        $planArgs = json_decode($toolCall['function']['arguments'], true);
        $tasks = $planArgs['steps'];

        echo "📋 [CEO] 任务拆解完成，生成计划:\n";
        foreach ($tasks as $t) {
            echo "   - 指派给 [{$t['worker']}]: {$t['task']}\n";
        }

        // 4. 任务分发与执行循环
        foreach ($tasks as $index => $step) {
            // 安全检查
            if (!isset($this->workers[$step['worker']])) {
                echo "⚠️ [CEO] 警告: LLM 指派了不存在的工人 '{$step['worker']}',跳过。\n";
                continue;
            }

            $worker = $this->workers[$step['worker']];
            echo "👉 [CEO] 分发任务 $index: 指派给 {$step['worker']}...\n";

            // 员工执行
            $result = $worker->execute($step['task'], $blackboard);

            $blackboard->completedTasks[] = [
                'worker' => $step['worker'],
                'result' => $result
            ];
        }

        // 5. 最终整合
        $this->synthesizeReport($blackboard);
    }

    private function synthesizeReport(Blackboard $blackboard): void
    {
        echo "\n🤵 [CEO] 整合最终报告...\n";
        $context = "";
        foreach ($blackboard->completedTasks as $task) {
            echo "\n🤵 [CEO] ...\n";
            $context .= "- {$task['worker']}: {$task['result']}\n";
        }
        echo "✅ 最终报告:\n$context";
    }
}

// ================= 运行演示 =================

$apiKey = '92507d8cd58b4ac5b15eacfb64543481.pQOxCfzRi6GRSh9d'; // <--- 填入你的 Key
$blackboard = new Blackboard();

// 1. 组建团队
$ceo = new CEOAgent('Boss', $apiKey);

// // 员工 A：搜索专家
// $searcher = new WorkerAgent('Searcher', 'Research Expert', $apiKey);
// $searcher->addTool([
//     "type" => "function",
//     "function" => [
//         "name" => "search_web",
//         "description" => "Search the web for information",
//         "parameters" => ["type" => "object", "properties" => ["q" => ["type" => "string"]], "required" => ["q"]]
//     ]
// ], function ($args) {
//     // sleep(1);
//     $news = [
//         "apple is fruit",
//         "man is human",
//     ];
//     return json_encode($news, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
//     return [rand(0, 100)] ? "Search Result: PHP 8.3 introduces Typed Class Constants (e.g. public const string NAME = 'test');" : "Search Result: No relevant information found.";
//     return "Search Result: PHP 8.3 introduces Typed Class Constants (e.g. public const string NAME = 'test');";
// });

// $ceo->addWorker($searcher);

// 员工 D：Shell命令执行专家
$sheller = new WorkerAgent('Sheller', 'Shell Command Executor', $apiKey);
$sheller->addTool([
    "type" => "function",
    "function" => [
        "name" => "bash",
        "description" => "Run a shell command in the current workspace. This is a powerful tool that can execute various system commands. Use it to inspect directories, read files, run PHP scripts, git operations, etc.",
        "parameters" => [
            "type" => "object",
            "properties" => [
                "command" => [
                    "type" => "string",
                    "description" => "The shell command to execute. Examples: 'ls -la', 'cat file.php', 'git status', 'php test.php'"
                ]
            ],
            "required" => ["command"]
        ]
    ]
], function ($args) {
    $command = $args['command'] ?? '';
    echo "🔍 执行命令: {$command}\n";

    // 安全检查：防止危险命令
    $dangerousPatterns = [
        '/rm\s+-rf\s+/', '/rm\s+-rf\s*\//', '/\|\s*>\s*\/dev\/', '/&>/', '/2>&1\s*>/',
        '/ssh\s+/', '/sudo\s+/', '/su\s+/', '/passwd\s+/', '/usermod\s+/', '/useradd\s+/',
        '/chmod\s+777/', '/chown\s+root/', '/killall\s+/', '/pkill\s+/',
        '/docker\s+/', '/podman\s+/', '/lxc\s+/', '/kvm\s+/', '/virsh\s+/',
        '/iptables\s+/', '/ufw\s+/', '/firewalld\s+/', '/nftables\s+/', '/systemd\s+/',
        '/system\s*\(/', '/exec\s*\(/', '/eval\s*\(/', '/passthru\s*\(/', '/shell_exec\s*\(/',
        '/popen\s*\(/', '/proc_open\s*\(/', '/fwrite\s*\(/'
    ];

    foreach ($dangerousPatterns as $pattern) {
        // if (preg_match($pattern, $command)) {
        //     return "❌ 安全拦截：命令包含危险操作 '{$command}'";
        // }
    }

    // 执行命令并返回结果
    $output = shell_exec($command . ' 2>&1');

    if ($output === null) {
        return "❌ 命令执行失败或无输出";
    }

    // 限制输出长度
    $maxOutputLength = 10240; // 10KB
    $outputLength = strlen($output);
    if ($outputLength > $maxOutputLength) {
        $output = substr($output, 0, $maxOutputLength) . "\n\n... (output truncated, total length: {$outputLength} bytes)";
    }

    return $output;
});

$ceo->addWorker($sheller);

// 员工 B：编程专家
// $coder = new WorkerAgent('Coder', 'Senior PHP Developer', $apiKey);
// $coder->addTool([
//     "type" => "function",
//     "function" => [
//         "name" => "write_code",
//         "description" => "Write PHP code based on requirements",
//         "parameters" => ["type" => "object", "properties" => ["code" => ["type" => "string"]], "required" => ["code"]]
//     ]
// ], function ($args) {
// //     // 1. 加载 JSON 配置
// //     $configFile = __DIR__ . '/../../config.json';
// //     if (!file_exists($configFile)) {
// //         die("❌ 配置文件不存在: $configFile\n");
// //     }
// //     $config = json_decode(file_get_contents($configFile), true);
// //     //json_decode 失败会返回 null
// //     if ($config === null) {
// //         echo json_last_error_msg();
// //         die("❌ 无法解析配置文件: $configFile\n");
// //     }
// //     // 2. 创建 LLM 工厂
// // // 我们只传递 LLM 相关的配置给工厂，保持解耦
// //     $llmFactory = new LLMFactory($config['llm']);
// //     $llm = $llmFactory->make();
// //     // 3. 创建 Agent
// //     $agent = new Agent($llm);
// //     $conservation = [];
// //     $conservation[] = [
// //         'role' => 'system',
// //         'content' => "You are a helpful assistant that writes PHP code based on requirements."
// //     ];
// //     $reply = $agent->chat('cli_user', $args['code'], $conservation);
// //     return "" . $reply;
//     // sleep(1);
//     file_put_contents('11generated_code.php', $args['code']);
//     return "Code Saved: " . $args['code'];
// });
// $ceo->addWorker($coder);


// 2. 下达目标
$goal = "写个php闭包的demo,要格式化";//"百度查下今天的新闻,制作成网页,再用格式化工具文本输出到网页";// "我想了解 PHP 8.3 的 Typed Constants，并写一个示例类。";
$arr = [
    '写个旅游方案,其它的你定',
    '本地是什么nodejs版本？',
    '帮我写一个php闭包的demo',
];
$goal= $arr[0];
// 3. 启动编排
$ceo->orchestrate($goal, $blackboard);
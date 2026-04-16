<?php

/**
 * 基础工具抽象类
 */
abstract class Tool
{
    // 工具名称 (对应 LLM 的 function name)
    public string $name;

    // 工具描述 (对应 LLM 的 function description)
    public string $desc;

    // 参数 schema，可用于补充 enum、format 等信息
    public array $parameterSchemas = [];

    /**
     * 构造函数，子类必须初始化 name 和 desc
     */
    public function __construct()
    {
        // 这里可以强制要求子类设置，或者在子类构造函数中设置
    }

    public function getParameterSchemas(): array
    {
        return $this->parameterSchemas;
    }

    /**
     * 执行逻辑
     * 子类实现具体的execute方法，参数根据需要定义
     * ToolManager 会通过反射获取参数定义
     */
    // abstract public function execute(array $params);
}

/**
 * 工具管理器：负责扫描工具并生成 LLM 定义
 */
class ToolManager
{
    private array $tools = [];

    /**
     * 注册一个工具实例
     */
    public function register(Tool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    /**
     * 获取所有工具的 JSON 定义 (传给 LLM 的格式)
     * 
     * @return array 符合 OpenAI Function Calling 标准的数组
     */
    public function getFunctionDefinitions(): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            $reflection = new ReflectionClass($tool);
            $method = $reflection->getMethod('execute');
            
            // 获取参数定义
            $properties = [];
            $requiredParams = [];

            $parameterSchemas = $tool->getParameterSchemas();

            foreach ($method->getParameters() as $param) {
                $paramName = $param->getName();
                
                // 简单的类型映射 (可以根据需要扩展)
                $type = 'string'; 
                if ($param->hasType()) {
                    $typeName = $param->getType()->getName();
                    if (in_array($typeName, ['int', 'float', 'bool', 'array', 'string'])) {
                        $type = $typeName;
                    }
                }

                $properties[$paramName] = array_merge(
                    [
                        'type' => $type,
                        'description' => $this->getParamDescription($param)
                    ],
                    $parameterSchemas[$paramName] ?? []
                );

                // 如果没有默认值，则为必填
                if (!$param->isDefaultValueAvailable()) {
                    $requiredParams[] = $paramName;
                }
            }

            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->desc,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $properties,
                        'required' => $requiredParams
                    ]
                ]
            ];
        }

        return $definitions;
    }

    /**
     * 执行工具
     */
    public function run(string $toolName, array $arguments)
    {
        if (!isset($this->tools[$toolName])) {
            throw new Exception("Tool not found: $toolName");
        }

        return call_user_func_array([$this->tools[$toolName], 'execute'], $arguments);
    }

    /**
     * 辅助方法：从参数中提取描述 (这里仅作示例，实际可使用 DocBlock 解析)
     */
    private function getParamDescription(ReflectionParameter $param): string
    {
        $method = $param->getDeclaringFunction();
        if ($method instanceof ReflectionMethod) {
            $docComment = $method->getDocComment();
            if ($docComment) {
                $lines = explode("\n", $docComment);
                foreach ($lines as $line) {
                    $line = trim($line, " \t*/");
                    if (preg_match('/@param\s+\w+\s+\$' . preg_quote($param->getName()) . '\s+(.+)/', $line, $matches)) {
                        return trim($matches[1]);
                    }
                }
            }
        }
        return "Parameter: " . $param->getName();
    }
}

// ==========================================
// 示例：定义具体的工具
// ==========================================

class GetWeatherTool extends Tool
{
    public function __construct()
    {
        $this->name = 'get_weather';
        $this->desc = '获取指定城市的天气信息';
    }

    /**
     * @param string $city 要获取天气的城市名称
     * @param int $days 要预测的天数，默认1
     */
    // 定义参数类型，Manager 会自动识别
    public function execute(string $city, int $days = 1)
    {
        return "正在查询 {$city} 未来 {$days} 天的天气... (模拟数据: 晴, 25°C)";
    }
}

class CalculatorTool extends Tool
{
    public function __construct()
    {
        $this->name = 'calculator';
        $this->desc = '执行基本的数学运算';
    }

    /**
     * @param float $a 第一个数字
     * @param float $b 第二个数字
     * @param string $operator 要执行的操作 (add, sub, mul, div)
     */
    public function execute(float $a, float $b, string $operator)
    {
        return match($operator) {
            'add' => $a + $b,
            'sub' => $a - $b,
            'mul' => $a * $b,
            'div' => $b !== 0 ? $a / $b : 'Error: Division by zero',
            default => 'Unknown operator'
        };
    }
}

class EditFileTool extends Tool
{
    public function __construct()
    {
        $this->name = 'edit_file';
        $this->desc = 'Edit file by replacing, inserting or appending content. The path must be absolute path.';
        $this->parameterSchemas = [
            'operation' => [
                'enum' => ['replace', 'insert', 'append', 'prepend'],
            ],
        ];
    }

    /**
     * @param string $path 文件绝对路径
     * @param string $operation 编辑操作类型，枚举值 replace/insert/append/prepend
     * @param string $new_content 新内容
     * @param string $old_content 旧内容，用于 replace 操作
     * @param int $line_number 插入时的目标行号（可选）
     */
    public function execute(string $path, string $operation, string $new_content, string $old_content = '', int $line_number = 0)
    {
        return "Edit file: {$path}, op={$operation}, line={$line_number}";
    }
}

class ReadFileTool extends Tool
{
    public function __construct()
    {
        $this->name = 'read_file';
        $this->desc = 'read a file by the path.结果将以cat -n格式返回,行号从1开始.The path must be absolute path.';
    }

    /**
     * @param string $path 要读取的文件绝对路径
     * @return string 文件内容，格式为 cat -n 输出
     */
    // 定义参数类型，Manager 会自动识别
    public function execute(string $path): string
    {
        if (!file_exists($path)) {
            return "错误：文件不存在 -> {$path}";
        }

        $lines = @file($path);
        if ($lines === false) {
            return "错误：无法读取文件 -> {$path}";
        }

        $output = '';
        foreach ($lines as $lineNumber => $line) {
            $output .= ($lineNumber + 1) . "\t" . $line;
        }

        return rtrim($output);
    }
}

// ==========================================
// 使用示例
// ==========================================

$manager = new ToolManager();

// 1. 注册工具
$manager->register(new GetWeatherTool());
$manager->register(new CalculatorTool());
$manager->register(new EditFileTool());
$manager->register(new ReadFileTool());

// 2. 生成传给 LLM 的定义
$llmFunctions = $manager->getFunctionDefinitions();

// 打印 JSON 看看效果
echo "=== LLM Function Definitions ===\n";
echo json_encode($llmFunctions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

echo "\n\n=== Execution Test ===\n";
// 3. 模拟 LLM 返回了调用指令，我们执行它
try {
    $result = $manager->run('get_weather', ['city' => 'Beijing', 'days' => 3]);
    echo "执行结果: " . $result;
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
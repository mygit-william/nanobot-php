<?php

declare(strict_types=1);

namespace App\Core;

use App\Tools\Tool;
use ReflectionClass;
use ReflectionParameter;

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
            throw new \Exception("Tool not found: $toolName");
        }
        var_dump($arguments);
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
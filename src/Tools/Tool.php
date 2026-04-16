<?php
namespace App\Tools;
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

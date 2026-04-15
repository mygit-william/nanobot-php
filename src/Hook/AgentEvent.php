<?php
namespace App\Hook;
// 2. 定义事件枚举
class AgentEvent
{
    const PRE_ACTION = '工具执行前';   // 执行动作前
    const POST_ACTION = '工具执行后'; // 执行动作后
    const PRE_THINK = '思考前';     // 思考前
    const POST_THINK = '思考后';   // 思考后
}
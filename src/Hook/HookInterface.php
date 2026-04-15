<?php
namespace App\Hook;
// 1. 定义 Hook 接口
interface HookInterface
{
    /**
     * 处理事件
     *
     * @param string $event 事件名称
     * @param array $context 上下文数据，包含事件相关的信息
     * @return array 返回修改后的上下文，或包含决策指令的数组
     */
    public function handle(string $event, array $context): array;
}
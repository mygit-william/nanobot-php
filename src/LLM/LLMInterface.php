<?php

namespace App\LLM;

interface LLMInterface
{
    /**
     * 发送对话请求并获取回复
     * @param array $messages 消息历史
     * @param array $tools 工具/技能定义列表
     * @return string 模型返回的文本内容
     */
    public function chat(array $messages, array $tools = []): string;
}
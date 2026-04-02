<?php

namespace App\Channels;

/**
 * 消息通道接口
 * 所有的聊天平台（钉钉、QQ、CLI）都必须实现此接口
 */
interface ChannelInterface
{
    /**
     * 获取通道名称 (例如: 'dingtalk', 'cli')
     */
    public function getName(): string;

    /**
     * 处理接收到的消息
     * @param array $payload 原始请求数据
     * @return void
     */
    public function receive(array $payload): void;

    /**
     * 发送消息给用户
     * @param string $sessionId 会话ID (用于区分不同用户或群聊)
     * @param string $message 消息内容
     * @return void
     */
    public function send(string $sessionId, string $message): void;
}
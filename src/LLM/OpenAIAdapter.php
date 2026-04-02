<?php

namespace App\LLM;

use GuzzleHttp\Client;

class OllamaAdapter implements LLMInterface
{
    private Client $client;
    private string $model;
    private string $baseUrl;

    public function __construct(string $baseUrl = 'http://localhost:11434', string $model = 'qwen:7b')
    {
        $this->client = new Client();
        $this->baseUrl = $baseUrl;
        $this->model = $model;
    }

    public function chat(array $messages, array $tools = []): string
    {
        try {
            $response = $this->client->post("{$this->baseUrl}/api/chat", [
                'json' => [
                    'model' => $this->model,
                    'messages' => $messages,
                    'stream' => false,
                    // Ollama 的工具调用格式比较特殊，这里简化处理，仅作为演示
                    // 实际生产可能需要根据工具定义动态构建 prompt
                ]
            ]);

            $body = json_decode($response->getBody(), true);
            return $body['message']['content'] ?? 'AI 没有返回内容';

        } catch (\Exception $e) {
            return "LLM 调用出错: " . $e->getMessage();
        }
    }
}
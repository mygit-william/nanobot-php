<?php

namespace App\LLM;
use GuzzleHttp\Client;

class ZhipuAdapter implements LLMInterface
{
    private Client $client;
    private string $model;
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $baseUrl = 'http://localhost:11434', string $model = 'qwen:7b', string $apiKey = '')
    {
        $this->client = new Client();
        $this->baseUrl = $baseUrl;
        $this->model = $model;
        $this->apiKey = $apiKey;
    }

    public function chat(array &$messages, array $tools = []): string
    {
        try {
            $response = $this->sendChatRequest(
                $this->baseUrl . "api/paas/v4/chat/completions",
                $this->model,
                $this->apiKey,
                $messages,
                $tools
            );

            $body = ($response);
            echo 'LLLM:' . json_encode($body, JSON_UNESCAPED_UNICODE) . PHP_EOL;
            return json_encode($body);

        } catch (\Exception $e) {
            return "LLM 调用出错: " . $e->getMessage();
        }
    }

    /**
     * 发送聊天请求到 OpenRouter API
     *
     * @param string $url API URL
     * @param string $apiKey API密钥
     * @param array $messages 消息数组
     * @return array 响应数据或null
     */
    function sendChatRequest($url, $model, $apiKey, &$messages,$tools)
    {
        // $tools = [
        //     [
        //         "type" => "function",
        //         "function" => [
        //             "name" => "bash",
        //             "description" => "Run a shell command in the current workspace.",
        //             "parameters" => [
        //                 "type" => "object",
        //                 "properties" => [
        //                     "command" => [
        //                         "type" => "string",
        //                         "description" => "要执行的命令"
        //                     ]
        //                 ],
        //                 "required" => ["command"]
        //             ]
        //         ]
        //     ]
        // ];
        // var_dump($apiKey);
        $payload = [
            "model" => $model,
            "messages" => $messages,
            "stream" => false,
            "temperature" => 1.0,
            'tools' => $tools, // 将工具定义传递给模型
        ];

        $ch = curl_init($url);

        // 智谱AI使用不同的认证头
        $headers = [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: application/json"
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // 设置超时时间（单位：秒）
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);  // 连接超时：30秒
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);        // 总超时：120秒
        //ssl
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // ⚠️ 仅测试环境使用
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);  // 关闭主机名验证
        echo "curl开始...\n";
        $response = curl_exec($ch);
        echo "curl结束...\n";
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        echo '原始messages数据:' . json_encode($messages,JSON_UNESCAPED_UNICODE ) . PHP_EOL;
        echo '原始响应数据:' . $response . PHP_EOL;
        curl_close($ch);

        if ($curlError) {
            echo "cURL错误: " . $curlError . "\n";
            return null;
        }

        if ($httpCode !== 200) {
            echo "HTTP错误: " . $httpCode . "\n";
            echo "响应: " . $response . "\n";

            // 尝试解析错误信息
            $errorData = json_decode($response, true);
            if ($errorData && isset($errorData['error'])) {
                echo "错误详情: " . $errorData['error']['message'] ?? json_encode($errorData['error']) . "\n";
            }
            return null;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "JSON解析错误: " . json_last_error_msg() . "\n";
            return (
                [
                    "thought" => "",
                    "reply" => !empty($response) ? $response : "LLM 调用失败，未获取到响应,再次尝试调用接口，看看是否有更详细的错误信息",
                    'confidence' => 0.0
                ]
            );
        }
        $data = [
            'reply' => $data['choices'][0]['message']['content'] ?? ' ai no reply',
            'tool' => $data['choices'][0]['message']['tool_calls'] ?? []//$data['choices'][0]['message']['content']
        ];
        return $data;
    }
    public function isStringValidJson(string $str): bool
    {
        json_decode($str);
        if (json_last_error() === JSON_ERROR_NONE) {
            return true;
        } else {
            return false;
        }
    }
}
<?php

namespace App\LLM;
use App\Utils\LoadingAnimation;

class LongcatAdapter implements LLMInterface
{
    private string $model;
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $baseUrl = 'http://localhost:11434', string $model = 'qwen:7b', string $apiKey = '')
    {
        $this->baseUrl = $baseUrl;
        $this->model = $model;
        $this->apiKey = $apiKey;
    }

    public function chat(array &$messages, array $tools = []): string
    {
        try {
            $response = $this->sendChatRequest(
                $this->baseUrl . '/v1/chat/completions',
                $this->model,
                $this->apiKey,
                $messages,
                $tools
            );

            $body = ($response);
            // echo 'LLLM:' . json_encode($body, JSON_UNESCAPED_UNICODE) . PHP_EOL;
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
         if (empty($messages)) {
            throw new \Exception("Message can not empty:".json_encode($messages), 1);    
        }
        $messages = $this->ensureUtf8($messages);
        $payload = [
            "model" => $model,
            "messages" => $messages,
            "stream" => false,
            "temperature" => 0.0,
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
        //         curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        // curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $download_size, $downloaded, $upload_size, $uploaded) use (&$last_spinner_update) {
        //     $now = time();
        //     if (1||$now - $last_spinner_update >= 1) {
        //         // 显示spinner
        //         echo "\r" . str_repeat(' ', 50) . "\r";
        //         $spinner_chars =['.', '..', '...', '....'];;
        //         static $spinner_index = 0;
        //         echo " " . $spinner_chars[$spinner_index % (count($spinner_chars)-1)] ;//. " ".$now;
        //         $spinner_index++;
        //         $last_spinner_update = $now;
        //     }
        //     return 0;
        // });
        $animation = new LoadingAnimation("AI思考中");
        $animation->start();
        // echo "curl开始...\n";
        $response = curl_exec($ch);
        $animation->stop();
        // echo "curl结束...\n";
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        // echo '原始messages数据:' . json_encode($messages,JSON_UNESCAPED_UNICODE ) . PHP_EOL;
        // if (json_last_error() !== JSON_ERROR_NONE) {
        //     echo "JSON解析错误: " . json_last_error_msg() . "\n";
        // }
        // echo '原始响应数据:' . $response . PHP_EOL;
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

            'reply' => $data['choices'][0]['message']['content'] ?? '',
            'tool' => $data['choices'][0]['message']['tool_calls'] ?? []//$data['choices'][0]['message']['content']
        ];
        return $data;
    }

    public function ensureUtf8($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = $this->ensureUtf8($value);
        }
    } elseif (is_string($data)) {
        // 尝试将字符串转换为 UTF-8
        // 如果源编码不确定，可以使用 'auto' 或指定 'GBK,UTF-8' 等
        if (!mb_check_encoding($data, 'UTF-8')) {
            $data = mb_convert_encoding($data, 'UTF-8', 'auto');
        }
    }
    return $data;
}
}
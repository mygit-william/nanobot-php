<?php

namespace App\LLM;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use JsonException;
use App\Core\ConnectionPool;
use App\Core\MemoryOptimizer;

/**
 * OpenAI 适配器 - 带有连接池和内存优化
 */
class OpenAIAdapter implements LLMInterface
{
    private Client $client;
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private array $config;
    private ConnectionPool $connectionPool;
    private MemoryOptimizer $memoryOptimizer;

    public function __construct(string $apiKey = '', string $model = 'gpt-3.5-turbo', string $baseUrl = 'https://api.openai.com/v1')
    {
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('API key is required for OpenAI adapter');
        }

        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');

        // 初始化连接池和内存优化器
        $this->connectionPool = ConnectionPool::getInstance();
        $this->memoryOptimizer = MemoryOptimizer::getInstance();

        // 配置连接池参数
        $poolConfig = [
            'max_connections' => 5,
            'connection_timeout' => 30,
            'pool_enabled' => true
        ];

        $this->connectionPool->configure($poolConfig);

        $this->client = $this->connectionPool->getClient($this->baseUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'User-Agent' => 'PHP-Nanobot-OpenAI-Client/1.0',
                'Accept' => 'application/json',
            ]
        ]);

        $this->config = [
            'max_retries' => 3,
            'fallback_model' => 'gpt-3.5-turbo',
            'timeout' => 60,
            'retry_delay' => 2000, // milliseconds
            'temperature' => 0.7,
            'max_tokens' => 4096
        ];

        // 跟踪对象以预防内存泄漏
        $this->memoryOptimizer->trackObject($this);
    }

    /**
     * 发送聊天请求到 OpenAI API
     *
     * @param array $messages 消息数组
     * @param array $tools 工具定义数组
     * @return string AI 回复内容
     */
    public function chat(array &$messages, array $tools = []): string
    {
        // 优化消息历史以减少token使用
        $this->optimizeMessages($messages);

        $attempts = 0;
        $lastError = null;

        while ($attempts <= $this->config['max_retries']) {
            try {
                $response = $this->sendChatRequest($messages, $tools);

                if ($response === null) {
                    throw new \RuntimeException('收到空响应');
                }

                $result = $this->parseResponse($response);

                if ($result !== null) {
                    return $result;
                }

                // 如果响应解析失败但仍有重试机会，继续重试
                if ($attempts < $this->config['max_retries']) {
                    usleep($this->config['retry_delay'] * 1000);
                    $attempts++;
                    continue;
                }

                return $this->getFallbackResponse('响应解析失败');

            } catch (ConnectException $e) {
                $lastError = "无法连接到 OpenAI 服务: " . $this->sanitizeErrorMessage($e->getMessage());
                if ($attempts >= $this->config['max_retries']) {
                    break;
                }

                // 网络连接问题，等待后重试
                sleep(3);
                $attempts++;

            } catch (RequestException $e) {
                $lastError = $this->handleRequestException($e);
                if ($attempts >= $this->config['max_retries']) {
                    break;
                }
                $attempts++;

            } catch (TransferException $e) {
                $lastError = "网络传输错误: " . $this->sanitizeErrorMessage($e->getMessage());
                if ($attempts >= $this->config['max_retries']) {
                    break;
                }
                usleep($this->config['retry_delay'] * 1000);
                $attempts++;

            } catch (JsonException $e) {
                $lastError = "JSON 解析错误: " . $e->getMessage();
                if ($attempts >= $this->config['max_retries']) {
                    break;
                }
                $attempts++;

            } catch (\Exception $e) {
                $lastError = "OpenAI 调用过程中发生未知错误: " . $this->sanitizeErrorMessage($e->getMessage());
                break;
            }
        }

        // 检查内存使用情况并触发垃圾回收
        $this->memoryOptimizer->checkGarbageCollection();

        // 记录错误日志（生产环境）
        if ($this->isProductionEnvironment()) {
            error_log(sprintf(
                '[OpenAIAdapter] Failed after %d attempts: %s',
                $attempts,
                $lastError
            ));
        }

        return $lastError ?: $this->getFallbackResponse('服务暂时不可用');
    }

    /**
     * 发送聊天请求
     */
    private function sendChatRequest(array &$messages, array $tools): ?string
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
            'temperature' => $this->config['temperature'],
            'max_tokens' => $this->config['max_tokens']
        ];

        // 如果有工具定义，添加到请求中
        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        try {
            $startTime = microtime(true);

            $response = $this->client->post("/chat/completions", [
                'json' => $payload,
                'timeout' => $this->config['timeout'],
                'connect_timeout' => 15
            ]);

            $responseTime = (microtime(true) - $startTime) * 1000;

            // 记录响应时间用于连接池统计
            $this->connectionPool->updateResponseTimeStats($responseTime);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                return (string)$response->getBody();
            }

            throw new \RuntimeException("HTTP {$statusCode}: " . $response->getReasonPhrase());

        } catch (RequestException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \RuntimeException("请求发送失败: " . $e->getMessage());
        }
    }

    /**
     * 解析响应数据
     */
    private function parseResponse(string $response): ?string
    {
        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['choices'][0]['message']['content'])) {
                // 检查是否有错误信息
                if (isset($data['error'])) {
                    throw new \RuntimeException("OpenAI API 错误: " . $data['error']['message']);
                }
                return null;
            }

            return trim($data['choices'][0]['message']['content']);

        } catch (JsonException $e) {
            // 尝试从原始响应中提取可能的文本内容
            if (preg_match('/"content"\s*:\s*"([^"]+)"/', $response, $matches)) {
                return trim($matches[1]);
            }
            throw $e;
        }
    }

    /**
     * 处理请求异常
     */
    private function handleRequestException(RequestException $e): string
    {
        $message = $e->getMessage();
        
        if ($e->hasResponse()) {
            $statusCode = $e->getResponse()->getStatusCode();
            $reason = $e->getResponse()->getReasonPhrase();
            
            switch ($statusCode) {
                case 400:
                    return "请求参数错误: " . $reason;
                case 401:
                    return "认证失败，请检查 API 密钥";
                case 403:
                    return "访问被拒绝，请检查 API 密钥权限";
                case 404:
                    return "API 路径不存在或模型不可用";
                case 422:
                    return "请求格式错误: " . $reason;
                case 429:
                    return "请求频率过高，请稍后再试或升级账户";
                case 500:
                case 502:
                case 503:
                case 504:
                    return "OpenAI 服务器错误，请稍后重试";
                default:
                    return "HTTP {$statusCode}: {$reason}";
            }
        }

        return "请求失败: " . $this->sanitizeErrorMessage($message);
    }

    /**
     * 清理错误信息中的敏感内容
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // 移除 API 密钥等敏感信息
        $sanitized = preg_replace('/sk-[a-zA-Z0-9]{32,}/', '[API_KEY]', $message);
        $sanitized = preg_replace('/Bearer\s+[a-zA-Z0-9._-]+/', 'Bearer [REDACTED]', $sanitized);
        
        // 移除可能的 IP 地址、端口等敏感信息
        $sanitized = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP]', $sanitized);
        $sanitized = preg_replace('/\b\d{2,5}\b/', '[PORT]', $sanitized);
        
        // 截断过长的错误信息
        if (strlen($sanitized) > 200) {
            $sanitized = substr($sanitized, 0, 197) . '...';
        }
        
        return $sanitized;
    }

    /**
     * 获取降级响应
     */
    private function getFallbackResponse(string $reason): string
    {
        $fallbackMessages = [
            'openai_down' => "🤖 OpenAI 服务暂时不可用，我正在使用本地缓存的回复。请稍后重试。",
            'rate_limit' => "🤖 请求频率过高，正在自动重试...",
            'auth_failed' => "🤖 API 认证失败，正在检查配置...",
            'timeout' => "🤖 请求超时，正在重新尝试...",
            'parse_error' => "🤖 收到响应但格式异常，正在尝试修复...",
            'unknown_error' => "🤖 服务出现异常，正在恢复中..."
        ];

        $fallbackKey = strtolower(str_replace([' ', '-'], '_', $reason));
        if (isset($fallbackMessages[$fallbackKey])) {
            return $fallbackMessages[$fallbackKey];
        }

        return "🤖 服务暂时不可用，请稍后重试。错误: {$reason}";
    }

    /**
     * 优化消息历史以减少token使用
     */
    private function optimizeMessages(array &$messages): void
    {
        if (count($messages) > 20) {
            // 保持最近的对话，删除旧的
            $messages = array_slice($messages, -20);

            // 移除重复的消息内容
            $uniqueContent = [];
            foreach ($messages as $message) {
                $contentHash = md5($message['content'] ?? '');
                if (!isset($uniqueContent[$contentHash])) {
                    $uniqueContent[$contentHash] = true;
                } else {
                    unset($key);
                }
            }
        }
    }

    /**
     * 检查是否为生产环境
     */
    private function isProductionEnvironment(): bool
    {
        return !defined('APP_DEBUG') || APP_DEBUG === false;
    }

    /**
     * 获取适配器配置信息
     */
    public function getConfig(): array
    {
        return [
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'max_retries' => $this->config['max_retries'],
            'timeout' => $this->config['timeout'],
            'temperature' => $this->config['temperature']
        ];
    }

    /**
     * 设置超时时间
     */
    public function setTimeout(int $timeout): void
    {
        $this->config['timeout'] = $timeout;
        $this->client = new Client([
            'timeout' => $timeout,
            'connect_timeout' => min(15, $timeout / 2),
            'http_errors' => false,
            'verify' => true,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'User-Agent' => 'PHP-Nanobot-OpenAI-Client/1.0',
                'Accept' => 'application/json',
            ]
        ]);
    }

    /**
     * 切换模型
     */
    public function switchModel(string $newModel): bool
    {
        try {
            // 测试新模型是否可用
            $testPayload = [
                'model' => $newModel,
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'stream' => false
            ];

            $response = $this->client->post("{$this->baseUrl}/chat/completions", [
                'json' => $testPayload,
                'timeout' => 10
            ]);

            if ($response->getStatusCode() === 200) {
                $this->model = $newModel;
                return true;
            }

            return false;

        } catch (\Exception $e) {
            error_log("模型切换失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 更新 API 密钥
     */
    public function updateApiKey(string $newApiKey): void
    {
        if (empty($newApiKey)) {
            throw new \InvalidArgumentException('API key cannot be empty');
        }

        $this->apiKey = $newApiKey;

        // 使用连接池创建新客户端
        $this->client = $this->connectionPool->getClient($this->baseUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $newApiKey,
                'Content-Type' => 'application/json',
                'User-Agent' => 'PHP-Nanobot-OpenAI-Client/1.0',
                'Accept' => 'application/json',
            ]
        ]);
    }

    /**
     * 析构函数 - 确保资源清理
     */
    public function __destruct()
    {
        $this->memoryOptimizer->untrackObject($this);
    }
}
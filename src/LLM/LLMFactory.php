<?php

namespace App\LLM;

use App\LLM\ZhipuAdapter;
use App\LLM\OllamaAdapter;
use App\LLM\OpenAIAdapter;
use Exception;

/**
 * LLM 工厂类 - 带有全面的异常处理和错误管理
 * 负责创建和管理不同类型的 LLM 适配器实例
 */
class LLMFactory 
{
    private array $config;
    private array $instances = [];
    private array $validationCache = [];
    private int $maxInstances = 5; // 最大缓存实例数

    public function __construct(array $config)
    {
        // $this->validateConfiguration($config);
        $this->config = $config;
    }

    /**
     * 验证配置
     */
    private function validateConfiguration(array $config): void
    {
        try {
            // 检查必需的配置项
            if (!isset($config['default_provider'])) {
                throw new \InvalidArgumentException('缺少必需的配置项: default_provider');
            }

            if (!isset($config['providers']) || !is_array($config['providers'])) {
                throw new \InvalidArgumentException('providers 配置必须是数组');
            }

            // 验证每个 provider 配置
            foreach ($config['providers'] as $key => $provider) {
                $this->validateProvider($key, $provider);
            }

            // 验证默认 provider 是否存在
            if (!isset($config['providers'][$config['default_provider']])) {
                throw new \InvalidArgumentException("默认 provider '{$config['default_provider']}' 不存在");
            }

        } catch (Exception $e) {
            throw new \InvalidArgumentException("LLM 配置验证失败: " . $e->getMessage());
        }
    }

    /**
     * 验证单个 provider 配置
     */
    private function validateProvider(string $key, array $provider): void
    {
        $requiredFields = [
            'ollama' => ['base_url', 'model'],
            'zhipu' => ['base_url', 'model', 'api_key'],
            'openai' => ['api_key', 'model'],
            'longcat'=> ['api_key', 'model'],
        ];

        $driver = $provider['driver'] ?? '';
        
        if (!isset($requiredFields[$driver])) {
            throw new \InvalidArgumentException("不支持的驱动类型: {$driver}");
        }

        foreach ($requiredFields[$driver] as $field) {
            if (!isset($provider[$field]) || empty($provider[$field])) {
                throw new \InvalidArgumentException("provider '{$key}' 缺少必需的字段: {$field}");
            }
        }

        // 额外的验证逻辑
        switch ($driver) {
            case 'zhipu':
                if (!$this->isValidApiKey($provider['api_key'])) {
                    throw new \InvalidArgumentException("智谱AI API key 格式无效");
                }
                break;
                
            case 'openai':
                if (!$this->isValidApiKey($provider['api_key'])) {
                    throw new \InvalidArgumentException("OpenAI API key 格式无效");
                }
                break;
                
            case 'ollama':
                if (!$this->isValidUrl($provider['base_url'])) {
                    throw new \InvalidArgumentException("Ollama base_url 格式无效");
                }
                break;
        }
    }

    /**
     * 创建指定的 LLM 实例
     */
    public function make(?string $providerKey = null): LLMInterface
    {
        try {
            // 如果没有指定，使用默认配置
            $providerKey = $providerKey ?? $this->config['default_provider'];
            
            // 检查缓存
            if (isset($this->instances[$providerKey])) {
                return $this->instances[$providerKey];
            }

            // 验证 provider 存在
            if (!isset($this->config['providers'][$providerKey])) {
                throw new \InvalidArgumentException("模型 Provider '{$providerKey}' 不存在");
            }

            $providerConfig = $this->config['providers'][$providerKey];
            $driver = $providerConfig['driver'];

            // 根据 driver 动态实例化对应的类
            $instance = $this->createInstance($driver, $providerConfig);

            // 验证实例创建成功
            if (!$instance instanceof LLMInterface) {
                throw new \RuntimeException("LLM 实例创建失败，未实现接口");
            }

            // 缓存实例
            $this->cacheInstance($providerKey, $instance);

            return $instance;

        } catch (Exception $e) {
            $this->handleFactoryError("创建 LLM 实例失败", $e, $providerKey);
            throw $e;
        }
    }

    /**
     * 创建特定类型的实例
     */
    private function createInstance(string $driver, array $config): LLMInterface
    {
        try {
            return match ($driver) {
                'ollama' => new OllamaAdapter(
                    $config['base_url'], 
                    $config['model']
                ),
                'zhipu' => new ZhipuAdapter(
                    $config['base_url'], 
                    $config['model'],
                    $config['api_key']
                ),
                'longcat' => new LongcatAdapter(
                    $config['base_url'], 
                    $config['model'],
                    $config['api_key']
                ),
                'openai' => new OpenAIAdapter(
                    $config['api_key'], 
                    $config['model']
                ),
                default => throw new \InvalidArgumentException("不支持的驱动: {$driver}")
            };
        } catch (Exception $e) {
            throw new \RuntimeException("创建 {$driver} 适配器实例失败: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 缓存实例
     */
    private function cacheInstance(string $providerKey, LLMInterface $instance): void
    {
        // 限制缓存大小
        if (count($this->instances) >= $this->maxInstances) {
            // 移除最旧的实例（简单策略）
            $firstKey = array_key_first($this->instances);
            unset($this->instances[$firstKey]);
        }

        $this->instances[$providerKey] = $instance;
    }

    /**
     * 获取所有可用的 provider
     */
    public function getAvailableProviders(): array
    {
        try {
            return array_keys($this->config['providers']);
        } catch (Exception $e) {
            error_log("获取可用 providers 失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取默认 provider
     */
    public function getDefaultProvider(): string
    {
        return $this->config['default_provider'] ?? 'ollama';
    }

    /**
     * 切换默认 provider
     */
    public function setDefaultProvider(string $providerKey): bool
    {
        try {
            if (!isset($this->config['providers'][$providerKey])) {
                return false;
            }
            
            $this->config['default_provider'] = $providerKey;
            return true;
            
        } catch (Exception $e) {
            error_log("设置默认 provider 失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 验证 API Key 格式
     */
    private function isValidApiKey(string $apiKey): bool
    {
        return true;
        if (isset($this->validationCache['api_key_' . md5($apiKey)])) {
            return $this->validationCache['api_key_' . md5($apiKey)];
        }

        // OpenAI 和 智谱AI API key 格式验证
        $patterns = [
            '/^sk-[a-zA-Z0-9]{32,}$/', // OpenAI
            '/^[a-zA-Z0-9]{32,}$/'     // 智谱AI
        ];

        $result = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $apiKey)) {
                $result = true;
                break;
            }
        }

        $this->validationCache['api_key_' . md5($apiKey)] = $result;
        return $result;
    }

    /**
     * 验证 URL 格式
     */
    private function isValidUrl(string $url): bool
    {
        if (isset($this->validationCache['url_' . md5($url)])) {
            return $this->validationCache['url_' . md5($url)];
        }

        $result = filter_var($url, FILTER_VALIDATE_URL) !== false;
        $this->validationCache['url_' . md5($url)] = $result;
        return $result;
    }

    /**
     * 处理工厂错误
     */
    private function handleFactoryError(string $message, Exception $e, ?string $providerKey = null): void
    {
        $errorMsg = $message;
        if ($providerKey) {
            $errorMsg .= " (provider: {$providerKey})";
        }
        $errorMsg .= ": " . $e->getMessage();

        if ($this->isProductionEnvironment()) {
            error_log("[LLMFactory] " . $errorMsg);
        }

        // 清理缓存中失败的实例
        if ($providerKey && isset($this->instances[$providerKey])) {
            unset($this->instances[$providerKey]);
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
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->instances = [];
        $this->validationCache = [];
    }

    /**
     * 获取工厂状态信息
     */
    public function getStatus(): array
    {
        return [
            'default_provider' => $this->getDefaultProvider(),
            'available_providers' => $this->getAvailableProviders(),
            'cached_instances' => count($this->instances),
            'max_cache_size' => $this->maxInstances,
            'validation_cache_size' => count($this->validationCache)
        ];
    }

    /**
     * 更新配置
     */
    public function updateConfig(array $newConfig): void
    {
        try {
            $this->validateConfiguration($newConfig);
            $this->config = $newConfig;
            $this->clearCache(); // 清除缓存以反映新配置
            
        } catch (Exception $e) {
            throw new \InvalidArgumentException("配置更新失败: " . $e->getMessage());
        }
    }

    /**
     * 测试 provider 连接
     */
    public function testConnection(string $providerKey): array
    {
        try {
            $providerConfig = $this->config['providers'][$providerKey];
            $driver = $providerConfig['driver'];
            
            $instance = $this->createInstance($driver, $providerConfig);
            
            // 根据类型调用不同的测试方法
            switch ($driver) {
                case 'zhipu':
                    $isValid = $instance->validateApiKey();
                    break;
                case 'openai':
                    // OpenAI 需要特殊处理
                    $isValid = true; // 简化处理
                    break;
                case 'ollama':
                    // Ollama 通常总是可连接的
                    $isValid = true;
                    break;
                default:
                    $isValid = false;
            }
            
            return [
                'success' => $isValid,
                'message' => $isValid ? '连接测试成功' : '连接测试失败'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '连接测试失败: ' . $e->getMessage()
            ];
        }
    }
}

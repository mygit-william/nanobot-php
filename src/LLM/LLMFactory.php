<?php

namespace App\LLM;

use App\LLM\ZhipuAdapter;
use App\LLM\OllamaAdapter;
use App\LLM\OpenAIAdapter;

class LLMFactory 
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 创建指定的 LLM 实例
     */
    public function make(?string $providerKey = null): LLMInterface
    {
        // 如果没有指定，使用默认配置
        $providerKey = $providerKey ?? $this->config['default_provider'];
        
        if (!isset($this->config['providers'][$providerKey])) {
            throw new \InvalidArgumentException("模型 Provider '{$providerKey}' 不存在");
        }

        $providerConfig = $this->config['providers'][$providerKey];
        $driver = $providerConfig['driver'];

        // 根据 driver 动态实例化对应的类
        return match ($driver) {
            'ollama' => new OllamaAdapter(
                $providerConfig['base_url'], 
                $providerConfig['model']
            ),
            'zhipu' => new ZhipuAdapter(
                    $providerConfig['base_url'], 
                    $providerConfig['model'],
                    $providerConfig['api_key']
            ),
            'openai' => new OpenAIAdapter(
                $providerConfig['api_key'], 
                $providerConfig['model']
            ),
            default => throw new \InvalidArgumentException("不支持的驱动: {$driver}")
        };
    }
}

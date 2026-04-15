<?php

namespace App\Tools;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Utils;
use JsonException;

/**
 * Web内容获取工具 - 获取URL内容并提取文本
 *
 * 支持功能：
 * - 获取网页内容
 * - 内容长度限制
 * - 超时控制
 * - 内容类型验证
 * - 简单的HTML文本提取
 */
class WebFetch
{
    private Client $client;
    private array $config;

    // 支持的内容类型
    private const ALLOWED_CONTENT_TYPES = [
        'text/html',
        'text/plain',
        'application/json',
        'application/xml',
        'text/xml',
        'application/javascript',
        'text/css',
        'application/pdf',
    ];

    // 最大内容长度 (1MB)
    private const MAX_CONTENT_LENGTH = 1048576;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'timeout' => 30,
            'connect_timeout' => 10,
            'max_content_length' => self::MAX_CONTENT_LENGTH,
            'allowed_content_types' => self::ALLOWED_CONTENT_TYPES,
            'cache_enabled' => true,
            'cache_ttl' => 1800, // 30分钟
            'user_agent' => 'PHP-Nanobot-WebFetch/1.0',
            'verify_ssl' => true,
            'follow_redirects' => true,
            'max_redirects' => 5,
            'extract_text' => true, // 是否提取文本内容
        ], $config);

        $this->client = new Client([
            'timeout' => $this->config['timeout'],
            'connect_timeout' => $this->config['connect_timeout'],
            'http_errors' => false,
            'verify' => $this->config['verify_ssl'],
            'allow_redirects' => $this->config['follow_redirects'],
            'max_redirects' => $this->config['max_redirects'],
            'headers' => [
                'User-Agent' => $this->config['user_agent'],
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                'Accept-Encoding' => 'gzip, deflate',
            ]
        ]);
    }

    /**
     * 获取URL内容
     *
     * @param string $url 目标URL
     * @param array $options 额外选项
     * @return array 包含内容、元数据和状态的数组
     * @throws \Exception 获取失败时抛出异常
     */
    public function fetch(string $url, array $options = []): array
    {
        if (empty(trim($url))) {
            throw new \InvalidArgumentException('URL不能为空');
        }

        // 验证URL格式
        if (!$this->isValidUrl($url)) {
            throw new \InvalidArgumentException('URL格式无效: ' . $url);
        }

        // 限制URL长度
        if (strlen($url) > 2048) {
            throw new \InvalidArgumentException('URL过长');
        }

        // 合并选项
        $fetchOptions = array_merge($this->config, $options);

        // 检查缓存
        $cacheKey = $this->generateCacheKey($url, $fetchOptions);
        if ($fetchOptions['cache_enabled'] && $cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        try {
            $result = $this->executeFetch($url, $fetchOptions);

            // 缓存结果
            if ($fetchOptions['cache_enabled']) {
                $this->saveToCache($cacheKey, $result);
            }

            return $result;

        } catch (ConnectException $e) {
            throw new \Exception("无法连接到目标URL: " . $this->sanitizeErrorMessage($e->getMessage()));
        } catch (RequestException $e) {
            throw new \Exception("请求失败: " . $this->sanitizeErrorMessage($e->getMessage()));
        } catch (\Exception $e) {
            throw new \Exception("获取内容失败: " . $this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    /**
     * 执行实际的获取请求
     */
    private function executeFetch(string $url, array $options): array
    {
        // 发起请求
        $response = $this->client->get($url, [
            'stream' => true, // 使用流式传输以支持大文件
            'headers' => $options['headers'] ?? [],
        ]);

        $statusCode = $response->getStatusCode();

        // 验证响应状态
        if ($statusCode !== 200) {
            throw new \Exception("HTTP {$statusCode}: " . $response->getReasonPhrase());
        }

        // 验证内容类型
        $contentType = $response->getHeaderLine('content-type');
        if (!$this->isValidContentType($contentType, $options['allowed_content_types'])) {
            throw new \Exception("不支持的内容类型: {$contentType}");
        }

        // 获取内容长度
        $contentLength = $response->getHeaderLine('content-length');
        if ($contentLength && $contentLength > $options['max_content_length']) {
            throw new \Exception("内容长度超过限制: {$contentLength} 字节");
        }

        // 读取内容（使用流式读取）
        $body = $response->getBody();
        $content = '';
        $bytesRead = 0;

        while (!$body->eof() && $bytesRead < $options['max_content_length']) {
            $chunk = $body->read(8192); // 8KB块读取
            $content .= $chunk;
            $bytesRead += strlen($chunk);

            if ($bytesRead >= $options['max_content_length']) {
                break;
            }
        }

        $body->close();

        // 提取文本内容
        $textContent = $options['extract_text'] ? $this->extractText($content, $contentType) : $content;

        return [
            'url' => $url,
            'status_code' => $statusCode,
            'content_type' => $contentType,
            'content_length' => $bytesRead,
            'content' => $textContent,
            'raw_content' => $content,
            'headers' => $this->getResponseHeaders($response),
            'fetched_at' => date('Y-m-d H:i:s'),
            'success' => true,
        ];
    }

    /**
     * 验证URL格式
     */
    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false &&
               in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https']);
    }

    /**
     * 验证内容类型
     */
    private function isValidContentType(string $contentType, array $allowedTypes): bool
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0]));

        foreach ($allowedTypes as $allowed) {
            if (strtolower(trim($allowed)) === $contentType) {
                return true;
            }
        }

        return false;
    }

    /**
     * 从HTML或XML中提取文本
     */
    private function extractText(string $content, string $contentType): string
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0]));

        switch ($contentType) {
            case 'text/html':
                return $this->extractHtmlText($content);
            case 'application/json':
                return $this->extractJsonText($content);
            case 'application/xml':
            case 'text/xml':
                return $this->extractXmlText($content);
            case 'application/javascript':
                return $this->extractJavaScriptText($content);
            case 'application/pdf':
                return $this->extractPdfText($content);
            default:
                return $content; // 文本类型直接返回
        }
    }

    /**
     * 从HTML中提取文本
     */
    private function extractHtmlText(string $html): string
    {
        // 移除script和style标签内容
        $text = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi', '', $html);
        $text = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/gi', '', $text);

        // 移除HTML标签，但保留段落结构
        $text = preg_replace('/<br\s*\/?>/gi', "\n", $text);
        $text = preg_replace('/<p\b[^>]*>/gi', "\n\n", $text);
        $text = preg_replace('/<div\b[^>]*>/gi', "\n", $text);
        $text = strip_tags($text);

        // 清理空白字符
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\n\s+\n/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    /**
     * 从JSON中提取文本
     */
    private function extractJsonText(string $json): string
    {
        try {
            $data = json_decode($json, true, 512, JSON_ERROR_NONE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $json; // 解析失败返回原始内容
            }

            // 递归提取所有字符串值
            $texts = [];
            $this->extractStringsFromArray($data, $texts);
            return implode("\n", array_filter($texts));
        } catch (\Exception $e) {
            return $json;
        }
    }

    /**
     * 从数组中提取所有字符串
     */
    private function extractStringsFromArray($data, array &$results, $depth = 0): void
    {
        if ($depth > 10) { // 防止递归过深
            return;
        }

        if (is_array($data)) {
            foreach ($data as $value) {
                $this->extractStringsFromArray($value, $results, $depth + 1);
            }
        } elseif (is_string($data) && !empty(trim($data))) {
            $results[] = trim($data);
        }
    }

    /**
     * 从XML中提取文本
     */
    private function extractXmlText(string $xml): string
    {
        try {
            $xmlObj = simplexml_load_string($xml);
            if ($xmlObj === false) {
                return $xml; // 解析失败返回原始内容
            }

            // 获取所有文本节点
            $text = strip_tags($xml);
            $text = preg_replace('/\s+/', ' ', $text);
            return trim($text);
        } catch (\Exception $e) {
            return $xml;
        }
    }

    /**
     * 从JavaScript中提取文本
     */
    private function extractJavaScriptText(string $js): string
    {
        // 移除注释
        $text = preg_replace('/\/\*.*?\*\/|\/\/.*?\n/s', '', $js);

        // 尝试提取字符串字面量
        preg_match_all('/"([^"]+)"|\'([^\']+)\'`([^`]+)`/', $text, $matches);
        $strings = array_merge($matches[1], $matches[2], $matches[3]);

        return implode("\n", array_filter(array_map('trim', $strings)));
    }

    /**
     * 从PDF中提取文本（简化版，实际应使用专门的PDF库）
     */
    private function extractPdfText(string $pdf): string
    {
        // 注意：这是一个简化实现，实际应该使用如pdftotext等工具
        // 这里仅返回提示信息
        return "[PDF文档内容 - 需要使用专门的PDF解析库来提取文本]";
    }

    /**
     * 获取响应头信息
     */
    private function getResponseHeaders($response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = $response->getHeaderLine($name);
        }
        return $headers;
    }

    /**
     * 生成缓存键
     */
    private function generateCacheKey(string $url, array $options): string
    {
        $keyData = [
            'url' => $url,
            'extract_text' => $options['extract_text'],
            'max_content_length' => $options['max_content_length'],
        ];
        return 'webfetch_' . md5(serialize($keyData));
    }

    /**
     * 从缓存获取结果
     */
    private function getFromCache(string $cacheKey): ?array
    {
        $cacheFile = $this->getCacheDir() . '/' . $cacheKey . '.json';

        if (!file_exists($cacheFile)) {
            return null;
        }

        $data = @file_get_contents($cacheFile);
        if ($data === false) {
            return null;
        }

        $cached = json_decode($data, true);

        // 检查缓存是否过期
        if (isset($cached['expire_at']) && time() > $cached['expire_at']) {
            @unlink($cacheFile);
            return null;
        }

        return $cached['result'] ?? null;
    }

    /**
     * 保存结果到缓存
     */
    private function saveToCache(string $cacheKey, array $result): void
    {
        $cacheDir = $this->getCacheDir();
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
            return; // 无法创建缓存目录
        }

        $cacheData = [
            'result' => $result,
            'expire_at' => time() + $this->config['cache_ttl'],
            'created_at' => time(),
        ];

        @file_put_contents(
            $cacheDir . '/' . $cacheKey . '.json',
            json_encode($cacheData, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 获取缓存目录
     */
    private function getCacheDir(): string
    {
        return __DIR__ . '/../../storage/cache/webfetch';
    }

    /**
     * 清理过期的缓存文件
     */
    public function clearExpiredCache(): int
    {
        $cacheDir = $this->getCacheDir();
        if (!is_dir($cacheDir)) {
            return 0;
        }

        $removed = 0;
        $files = glob($cacheDir . '/*.json');

        foreach ($files as $file) {
            $data = @file_get_contents($file);
            if ($data !== false) {
                $cached = json_decode($data, true);
                if (isset($cached['expire_at']) && time() > $cached['expire_at']) {
                    @unlink($file);
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * 清理所有缓存
     */
    public function clearAllCache(): int
    {
        $cacheDir = $this->getCacheDir();
        if (!is_dir($cacheDir)) {
            return 0;
        }

        $removed = 0;
        $files = glob($cacheDir . '/*.json');

        foreach ($files as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * 清理错误信息中的敏感内容
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // 移除可能的敏感信息
        $sanitized = preg_replace('/password\s*=\s*[^\s]+/i', 'password=***', $message);
        $sanitized = preg_replace('/key\s*=\s*[^\s]+/i', 'key=***', $sanitized);
        $sanitized = preg_replace('/secret\s*=\s*[^\s]+/i', 'secret=***', $sanitized);
        $sanitized = preg_replace('/token\s*=\s*[^\s]+/i', 'token=***', $sanitized);

        // 截断过长的错误信息
        if (strlen($sanitized) > 200) {
            $sanitized = substr($sanitized, 0, 197) . '...';
        }

        return $sanitized;
    }

    /**
     * 获取当前配置
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
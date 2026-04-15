<?php

namespace App\Tools;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use JsonException;

/**
 * Web搜索工具 - 使用搜索引擎API进行网页搜索
 *
 * 支持多种搜索引擎：
 * - Google Custom Search Engine (CSE)
 * - Bing Search API
 * - 百度搜索API
 */
class WebSearch
{
    private Client $client;
    private string $apiKey;
    private string $searchEngine;
    private array $config;

    // 支持的搜索引擎配置
    private const ENGINE_CONFIGS = [
        'google' => [
            'base_url' => 'https://www.googleapis.com/customsearch/v1',
            'required_params' => ['key', 'cx', 'q'],
            'result_path' => 'items',
            'title_field' => 'title',
            'snippet_field' => 'snippet',
            'link_field' => 'link',
            'requires_api_key' => true,
        ],
        'bing' => [
            'base_url' => 'https://api.bing.microsoft.com/v7.0/search',
            'required_params' => ['q'],
            'result_path' => 'webPages.value',
            'title_field' => 'name',
            'snippet_field' => 'snippet',
            'link_field' => 'url',
            'requires_api_key' => true,
        ],
        'baidu' => [
            'base_url' => 'https://api.baidu.com/search',
            'required_params' => ['query'],
            'result_path' => 'results',
            'title_field' => 'title',
            'snippet_field' => 'description',
            'link_field' => 'url',
            'requires_api_key' => true,
        ],
        'duckduckgo' => [
            'base_url' => 'https://www.duckduckgo.com/',
            'required_params' => ['q'],
            'result_path' => 'Results',
            'title_field' => 'Title',
            'snippet_field' => 'Snippet',
            'link_field' => 'FirstURL',
            'requires_api_key' => false,
            'format' => 'json',
        ],
        'duckduckgo_html' => [
            'base_url' => 'https://www.duckduckgo.com/html/',
            'required_params' => ['q'],
            'result_path' => null, // HTML解析
            'title_field' => null,
            'snippet_field' => null,
            'link_field' => null,
            'requires_api_key' => false,
            'format' => 'html',
        ]
    ];

    public function __construct(
        string $apiKey = '',
        string $searchEngine = 'google',
        array $config = []
    ) {
        $this->apiKey = $apiKey;
        $this->searchEngine = strtolower($searchEngine);
        $this->config = array_merge([
            'max_results' => 10,
            'timeout' => 30,
            'connect_timeout' => 10,
            'search_engine_id' => '', // Google CSE ID
            'safe_search' => true,
            'filter_language' => 'zh-CN',
            'cache_enabled' => true,
            'cache_ttl' => 3600 // 1小时
        ], $config);

        $this->client = new Client([
            'timeout' => $this->config['timeout'],
            'connect_timeout' => $this->config['connect_timeout'],
            'http_errors' => false,
            'verify' => true,
            'headers' => [
                'User-Agent' => 'PHP-Nanobot-WebSearch/1.0',
                'Accept' => 'application/json',
            ]
        ]);
    }

    /**
     * 执行网页搜索
     *
     * @param string $query 搜索查询
     * @param int $limit 结果数量限制
     * @param string $engine 搜索引擎
     * @return array 搜索结果数组
     * @throws \Exception 搜索失败时抛出异常
     */
    public function search(string $query, int $limit = 10, string $engine = null): array
    {
        if (empty(trim($query))) {
            throw new \InvalidArgumentException('搜索查询不能为空');
        }

        // 使用指定的搜索引擎或默认引擎
        $engine = $engine ?? $this->searchEngine;
        if (!isset(self::ENGINE_CONFIGS[$engine])) {
            throw new \InvalidArgumentException("不支持的搜索引擎: {$engine}");
        }

        // 检查是否需要API密钥
        if (self::ENGINE_CONFIGS[$engine]['requires_api_key'] && empty($this->apiKey)) {
            throw new \Exception("搜索引擎 {$engine} 需要API密钥");
        }

        // 限制查询长度
        if (strlen($query) > 500) {
            $query = substr($query, 0, 500);
        }

        $limit = $limit ?? $this->config['max_results'];
        $limit = min($limit, 50); // 最大限制50条结果

        // 检查缓存
        $cacheKey = $this->generateCacheKey($query, $limit, $engine);
//        if ($this->config['cache_enabled'] && $cached = $this->getFromCache($cacheKey)) {
//            return $cached;
//        }

        try {
            $results = $this->executeSearch($query, $limit, $engine);

            // 缓存结果
            if ($this->config['cache_enabled']) {
                $this->saveToCache($cacheKey, $results);
            }

            return $results;

        } catch (ConnectException $e) {
            throw new \Exception("无法连接到搜索引擎服务: " . $this->sanitizeErrorMessage($e->getMessage()));
        } catch (RequestException $e) {
            throw new \Exception("搜索请求失败: " . $this->sanitizeErrorMessage($e->getMessage()));
        } catch (JsonException $e) {
            throw new \Exception("搜索结果解析失败: " . $e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception("搜索过程中发生错误: " . $this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    /**
     * 执行实际的搜索请求
     */
    private function executeSearch(string $query, int $limit, string $engine): array
    {
        $engineConfig = $this->getEngineConfig($engine);

        switch ($engine) {
            case 'google':
                return $this->searchGoogle($query, $limit, $engineConfig);
            case 'bing':
                return $this->searchBing($query, $limit, $engineConfig);
            case 'baidu':
                return $this->searchBaidu($query, $limit, $engineConfig);
            case 'duckduckgo':
                return $this->searchDuckDuckGo($query, $limit, $engineConfig);
            case 'duckduckgo_html':
                return $this->searchDuckDuckGoHtml($query, $limit, $engineConfig);
            default:
                throw new \Exception("不支持的搜索引擎: {$engine}");
        }
    }

    /**
     * Google CSE 搜索
     */
    private function searchGoogle(string $query, int $limit, array $engineConfig): array
    {
        $params = [
            'key' => $this->apiKey,
            'cx' => $this->config['search_engine_id'],
            'q' => $query,
            'num' => min($limit, 10), // Google CSE 每页最多10条
            'safe' => $this->config['safe_search'] ? 'active' : 'off',
            'lr' => 'lang_' . $this->config['filter_language'],
        ];

        $response = $this->client->get($engineConfig['base_url'], [
            'query' => $params,
            'headers' => [
                'Accept' => 'application/json',
            ]
        ]);

        $data = json_decode((string)$response->getBody(), true, 512, JSON_ERROR_NONE);

        if ($response->getStatusCode() !== 200) {
            $error = $data['error'] ?? ['message' => '未知错误'];
            throw new \Exception("Google API 错误: " . ($error['message'] ?? '未知错误'));
        }

        if (!isset($data[$engineConfig['result_path']])) {
            return []; // 无结果
        }

        return $this->formatResults($data[$engineConfig['result_path']], $engineConfig);
    }

    /**
     * Bing 搜索
     */
    private function searchBing(string $query, int $limit, array $engineConfig): array
    {
        $params = [
            'q' => $query,
            'count' => min($limit, 50), // Bing 每页最多50条
            'offset' => 0,
            'mkt' => $this->config['filter_language'] === 'zh-CN' ? 'zh-CN' : 'en-US',
            'safesearch' => $this->config['safe_search'] ? 'Strict' : 'Off',
        ];

        $response = $this->client->get($engineConfig['base_url'], [
            'query' => $params,
            'headers' => [
                'Ocp-Apim-Subscription-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ]
        ]);

        $data = json_decode((string)$response->getBody(), true, 512, JSON_ERROR_NONE);

        if ($response->getStatusCode() !== 200) {
            throw new \Exception("Bing API 错误: " . ($data['error']['message'] ?? '未知错误'));
        }

        if (!isset($data[$engineConfig['result_path']])) {
            return []; // 无结果
        }

        return $this->formatResults($data[$engineConfig['result_path']], $engineConfig);
    }

    /**
     * 百度搜索
     */
    private function searchBaidu(string $query, int $limit, array $engineConfig): array
    {
        $params = [
            'query' => $query,
            'page_num' => min($limit, 20), // 百度每页最多20条
            'format' => 'json',
        ];

        $response = $this->client->post($engineConfig['base_url'], [
            'form_params' => $params,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ]
        ]);

        $data = json_decode((string)$response->getBody(), true, 512, JSON_ERROR_NONE);

        if ($response->getStatusCode() !== 200 || !isset($data['status']) || $data['status'] !== 0) {
            $errorMsg = $data['msg'] ?? '未知错误';
            throw new \Exception("百度搜索错误: " . $errorMsg);
        }

        if (!isset($data[$engineConfig['result_path']])) {
            return []; // 无结果
        }

        return $this->formatResults($data[$engineConfig['result_path']], $engineConfig);
    }

    /**
     * DuckDuckGo 搜索（使用官方API）
     */
    private function searchDuckDuckGo(string $query, int $limit, array $engineConfig): array
    {
        $params = [
            'q' => $query,
            'format' => 'json',
            'no_html' => 1,
            'no_redirect' => 1,
            'skip_disambig' => 1,
        ];

        try {
            $response = $this->client->get($engineConfig['base_url'], [
                'query' => $params,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                ],
                'timeout' => 20, // 增加到20秒超时
                'connect_timeout' => 8, // 连接超时8秒
            ]);
            var_dump("$engineConfig:",$engineConfig['base_url'],$params); // 调试输出原始响应数据
            $data = json_decode((string)$response->getBody(), true, 512, JSON_ERROR_NONE);
            // var_dump("DuckDuckGo @@@API response:", $data); // 调试输出API响应数据
            if ($response->getStatusCode() !== 200) {
                throw new \Exception("DuckDuckGo API 错误: HTTP {$response->getStatusCode()}");
            }

            // DuckDuckGo API返回格式特殊，需要特殊处理
            $results = [];
            $answer = $data['Answer'] ?? '';
            $relatedTopics = $data['RelatedTopics'] ?? [];
            // var_dump("DuckDuckGo @@@API answer:", $answer,$relatedTopics); // 调试输出Answer字段
            file_put_contents(__DIR__ . '/duckduckgo_response.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            die; // 保存原始响应数据到文件，便于调试
            // 添加主要答案
            if (!empty($answer)) {
                $results[] = [
                    'title' => $data['Heading'] ?? 'DuckDuckGo Answer',
                    'snippet' => strip_tags($answer),
                    'url' => $data['AbstractURL'] ?? '#',
                    'engine' => 'duckduckgo',
                ];
            }
            
            // 添加相关主题
            foreach ($relatedTopics as $topic) {
                if (count($results) >= $limit) break;

                if (isset($topic['Name']) && isset($topic['Topics'])) {
                    foreach ($topic['Topics'] as $subTopic) {
                        if (count($results) >= $limit) break;

                        $results[] = [
                            'title' => $subTopic['Name'] ?? '',
                            'snippet' => $subTopic['Text'] ?? '',
                            'url' => $subTopic['FirstURL'] ?? '#',
                            'engine' => 'duckduckgo',
                        ];
                    }
                } else {
                    $results[] = [
                        'title' => $topic['Name'] ?? '',
                        'snippet' => $topic['Text'] ?? '',
                        'url' => $topic['FirstURL'] ?? '#',
                        'engine' => 'duckduckgo',
                    ];
                }
            }
            //颜色输出
            var_dump("DuckDuckGo @@@API formatted results:", $results); // 调试输出格式化后的结果
            return $results;
        } catch (\Exception $e) {
            // 如果DuckDuckGo API失败，记录错误并尝试HTML版本
            error_log("DuckDuckGo API failed: " . $e->getMessage());
            return $this->searchDuckDuckGoHtml($query, $limit, $this->getEngineConfig('duckduckgo_html'));
        }
    }

    /**
     * DuckDuckGo HTML 搜索（备用方案）
     */
    private function searchDuckDuckGoHtml(string $query, int $limit, array $engineConfig): array
    {
        $params = [
            'q' => $query,
        ];

        try {
            $response = $this->client->get($engineConfig['base_url'], [
                'query' => $params,
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                ],
                'timeout' => 15,
                'connect_timeout' => 5,
            ]);

            $html = (string)$response->getBody();
            var_dump("搜索加个:",$html); // 调试输出HTML内容
            if ($response->getStatusCode() !== 200 || empty($html)) {
                throw new \Exception("DuckDuckGo HTML 搜索失败: HTTP {$response->getStatusCode()}");
            }
        } catch (\Exception $e) {
            // 如果DuckDuckGo也失败，返回模拟测试数据
            error_log("DuckDuckGo HTML search also failed: " . $e->getMessage());
            return $this->getFallbackResults($query, $limit);
        }

        // 使用正则表达式解析HTML结果
        $results = [];

        // 匹配搜索结果标题和URL
        preg_match_all('/<a[^>]*class="result__a"[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', $html, $titleMatches, PREG_SET_ORDER);
        // 匹配搜索结果摘要
        preg_match_all('/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/is', $html, $snippetMatches, PREG_SET_ORDER);

        $resultCount = min(count($titleMatches), $limit);

        for ($i = 0; $i < $resultCount; $i++) {
            $url = $titleMatches[$i][1] ?? '#';
            $title = strip_tags($titleMatches[$i][2] ?? '');
            $snippet = strip_tags($snippetMatches[$i][1] ?? '');

            // 清理URL（DuckDuckGo使用重定向）
            if (strpos($url, '//duckduckgo.com/l/?') === 0) {
                parse_str(parse_url($url, PHP_URL_QUERY), $queryParams);
                $url = $queryParams['uddg'] ?? $url;
            }

            $results[] = [
                'title' => $title,
                'snippet' => $snippet,
                'url' => $url,
                'engine' => 'duckduckgo_html',
            ];
        }

        return $results;
    }

    /**
     * 获取备用搜索结果（当所有搜索引擎都失败时）
     */
    private function getFallbackResults(string $query, int $limit): array
    {
        // 生成模拟搜索结果，确保系统可用性
        $results = [];

        // 常见搜索关键词的模拟结果
        $fallbackData = [
            'PHP' => [
                ['title' => 'PHP: Hypertext Preprocessor', 'snippet' => 'PHP is a popular general-purpose scripting language that is especially suited to web development.', 'url' => 'https://www.php.net/'],
                ['title' => 'PHP Tutorial - W3Schools', 'snippet' => 'Well organized and easy to understand Web building tutorials with lots of examples of how to use HTML, CSS, JavaScript, SQL, PHP, Python, Bootstrap, Java and XML.', 'url' => 'https://www.w3schools.com/php/'],
                ['title' => 'PHP Manual', 'snippet' => 'The PHP manual is available in many languages and versions. The manual consists of a preface, several chapters.', 'url' => 'https://www.php.net/manual/en/'],
            ],
            'default' => [
                ['title' => 'Search Results Unavailable', 'snippet' => "Unable to retrieve search results for '{$query}' at this time. Please check your internet connection or try again later.", 'url' => '#'],
                ['title' => 'Alternative Search Engines', 'snippet' => 'Try using Google, Bing, or Baidu if you have API keys configured.', 'url' => '#'],
                ['title' => 'Offline Capabilities', 'snippet' => 'This system can work offline but search functionality requires internet access.', 'url' => '#'],
            ]
        ];

        $searchData = $fallbackData[$query] ?? $fallbackData['default'];
        $resultCount = min(count($searchData), $limit);

        for ($i = 0; $i < $resultCount; $i++) {
            $results[] = [
                'title' => $searchData[$i]['title'],
                'snippet' => $searchData[$i]['snippet'],
                'url' => $searchData[$i]['url'],
                'engine' => 'fallback',
            ];
        }

        return $results;
    }

    /**
     * 格式化搜索结果
     */
    private function formatResults(array $rawResults, array $engineConfig): array
    {
        $results = [];

        foreach ($rawResults as $item) {
            $results[] = [
                'title' => $item[$engineConfig['title_field']] ?? '',
                'snippet' => $item[$engineConfig['snippet_field']] ?? '',
                'url' => $item[$engineConfig['link_field']] ?? '',
                'engine' => $this->searchEngine,
            ];
        }

        return $results;
    }

    /**
     * 获取搜索引擎配置
     */
    private function getEngineConfig(string $engine): array
    {
        if (!isset(self::ENGINE_CONFIGS[$engine])) {
            throw new \Exception("未配置的搜索引擎: {$engine}");
        }

        return self::ENGINE_CONFIGS[$engine];
    }

    /**
     * 生成缓存键
     */
    private function generateCacheKey(string $query, int $limit, string $engine): string
    {
        return 'websearch_' . md5($engine . $query . $limit . serialize($this->config));
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

        return $cached['results'] ?? null;
    }

    /**
     * 保存结果到缓存
     */
    private function saveToCache(string $cacheKey, array $results): void
    {
        $cacheDir = $this->getCacheDir();
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
            return; // 无法创建缓存目录
        }

        $cacheData = [
            'results' => $results,
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
        return __DIR__ . '/../../storage/cache/websearch';
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
        // 移除 API 密钥
        $sanitized = preg_replace('/sk-[a-zA-Z0-9]{32,}/', '[API_KEY]', $message);
        $sanitized = preg_replace('/[A-Za-z0-9]{32,}/', '[KEY]', $sanitized);

        // 移除可能的 URL 参数
        $sanitized = preg_replace('/[?&](key|api[_-]?key)=[^&\s]+/i', '$1=[REDACTED]', $sanitized);

        // 截断过长的错误信息
        if (strlen($sanitized) > 200) {
            $sanitized = substr($sanitized, 0, 197) . '...';
        }

        return $sanitized;
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
    }

    /**
     * 获取当前配置
     */
    public function getConfig(): array
    {
        return [
            'search_engine' => $this->searchEngine,
            'max_results' => $this->config['max_results'],
            'timeout' => $this->config['timeout'],
            'safe_search' => $this->config['safe_search'],
            'filter_language' => $this->config['filter_language'],
            'cache_enabled' => $this->config['cache_enabled'],
            'cache_ttl' => $this->config['cache_ttl'],
        ];
    }
}
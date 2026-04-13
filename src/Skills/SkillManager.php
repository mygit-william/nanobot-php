<?php

namespace App\Skills;

use Symfony\Component\Yaml\Yaml;
use Exception;

/**
 * 技能管理器 - 带有全面的异常处理和错误管理
 * 负责加载和管理各种 AI 技能
 */
class SkillManager
{
    public array $skills = [];
    private array $config;
    private bool $isLoaded = false;
    private array $errorLog = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'skill_directory' => __DIR__ . '/../../skills',
            'max_skill_size' => 1048576, // 1MB
            'default_permissions' => ['read', 'write'],
            'enable_caching' => true,
            'cache_ttl' => 3600, // 1 hour
            'log_errors' => true,
            'security_level' => 'strict' // strict, moderate, permissive
        ], $config);

        // 确保技能目录存在
        $this->ensureSkillDirectory();
    }

    /**
     * 确保技能目录存在
     */
    private function ensureSkillDirectory(): void
    {
        try {
            if (!is_dir($this->config['skill_directory'])) {
                if (!mkdir($this->config['skill_directory'], 0755, true)) {
                    throw new Exception("无法创建技能目录: {$this->config['skill_directory']}");
                }
                $this->logError("技能目录已创建");
            }
        } catch (Exception $e) {
            $this->handleCriticalError("技能目录初始化失败", $e);
        }
    }

    /**
     * 加载所有技能
     */
    public function loadSkills(): void
    {
        if ($this->isLoaded && $this->config['enable_caching']) {
            return; // 已经加载过且启用了缓存
        }

        $this->isLoaded = false;
        
        try {
            $skillFiles = $this->scanSkillDirectories();
            
            foreach ($skillFiles as $skillFile) {
                $this->loadSingleSkill($skillFile);
            }

            $this->isLoaded = true;
            $this->logInfo("成功加载 " . count($this->skills) . " 个技能");

        } catch (Exception $e) {
            $this->handleCriticalError("技能加载失败", $e);
            throw $e;
        }
    }

    /**
     * 扫描技能目录
     */
    private function scanSkillDirectories(): array
    {
        try {
            $files = [];
            $directories = scandir($this->config['skill_directory']);
            
            if ($directories === false) {
                throw new Exception("无法读取技能目录");
            }

            foreach ($directories as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $skillPath = $this->config['skill_directory'] . "/{$item}";
                
                if (!is_dir($skillPath)) {
                    continue;
                }

                $skillFile = $skillPath . "/SKILL.md";
                
                if (file_exists($skillFile)) {
                    $files[] = [
                        'name' => $item,
                        'path' => $skillFile,
                        'directory' => $skillPath
                    ];
                }
            }

            return $files;

        } catch (Exception $e) {
            $this->logError("扫描技能目录失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 加载单个技能
     */
    private function loadSingleSkill(array $skillInfo): void
    {
        try {
            // 验证文件大小
            $fileSize = filesize($skillInfo['path']);
            if ($fileSize > $this->config['max_skill_size']) {
                throw new Exception("技能文件过大: {$skillInfo['name']} ({$fileSize} bytes)");
            }

            // 读取文件内容
            $content = file_get_contents($skillInfo['path']);
            if ($content === false) {
                throw new Exception("无法读取技能文件: {$skillInfo['name']}");
            }

            // 安全地解析 YAML 头部
            $meta = $this->parseSkillMetadata($content, $skillInfo['name']);

            if ($meta === null) {
                $this->logError("跳过无效的技能文件: {$skillInfo['name']}");
                return;
            }

            // 验证技能元数据
            $this->validateSkillMetadata($meta, $skillInfo['name']);

            // 实例化技能
            $skillInstance = new OpenClawSkill($meta);
            
            // 添加到技能列表
            $this->skills[$meta['name']] = $skillInstance;
            
            $this->logInfo("成功加载技能: {$meta['name']}");

        } catch (Exception $e) {
            $this->logError("加载技能 {$skillInfo['name']} 失败: " . $e->getMessage());
            // 根据安全级别决定是否继续
            if ($this->config['security_level'] === 'strict') {
                throw $e;
            }
        }
    }

    /**
     * 解析技能元数据
     */
    private function parseSkillMetadata(string $content, string $skillName): ?array
    {
        try {
            // 使用正则表达式提取 YAML 头部
            if (!preg_match('/^---\s*(.*?)\s*---/s', $content, $matches)) {
                $this->logError("技能 {$skillName} 缺少 YAML 头部");
                return null;
            }

            $yamlContent = $matches[1];
            
            // 验证 YAML 语法
            if (empty(trim($yamlContent))) {
                throw new Exception("YAML 内容为空");
            }

            // 使用 Symfony YAML 解析器
            $parsed = Yaml::parse($yamlContent);
            
            if (!is_array($parsed)) {
                throw new Exception("YAML 解析结果不是数组");
            }

            // 添加默认字段
            $parsed = array_merge([
                'name' => $skillName,
                'version' => '1.0.0',
                'author' => 'unknown',
                'permissions' => $this->config['default_permissions']
            ], $parsed);

            return $parsed;

        } catch (Exception $e) {
            $this->logError("解析技能 {$skillName} 元数据失败: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 验证技能元数据
     */
    private function validateSkillMetadata(array $meta, string $skillName): void
    {
        $requiredFields = ['name', 'description'];
        $errors = [];

        // 检查必需字段
        foreach ($requiredFields as $field) {
            if (!isset($meta[$field]) || empty(trim($meta[$field]))) {
                $errors[] = "缺少必需字段: {$field}";
            }
        }

        // 验证名称格式
        if (isset($meta['name'])) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $meta['name'])) {
                $errors[] = "技能名称格式无效";
            }
            if (strlen($meta['name']) > 50) {
                $errors[] = "技能名称过长";
            }
        }

        // 验证权限
        if (isset($meta['permissions']) && is_array($meta['permissions'])) {
            $validPermissions = ['read', 'write', 'execute', 'network'];
            foreach ($meta['permissions'] as $permission) {
                if (!in_array($permission, $validPermissions)) {
                    $errors[] = "无效的权限: {$permission}";
                }
            }
        }

        if (!empty($errors)) {
            throw new Exception(implode(', ', $errors));
        }
    }

    /**
     * 获取工具定义
     */
    public function getToolsDefinition(): array
    {
        try {
            if (!$this->isLoaded) {
                $this->loadSkills();
            }

            $tools = [];
            $skillNames = [];

            foreach ($this->skills as $name => $skill) {
                // 安全检查
                if (!$this->canExecuteSkill($name)) {
                    $this->logWarning("跳过被限制的技能: {$name}");
                    continue;
                }

                try {
                    $description = $skill->getDescription();
                    $parameters = $skill->getParameters();

                    // 验证参数格式
                    if (!$this->validateToolParameters($parameters)) {
                        $this->logError("技能 {$name} 参数格式无效，跳过");
                        continue;
                    }

                    $tools[] = [
                        'type' => 'function',
                        'function' => [
                            'name' => $name,
                            'description' => $description,
                            'parameters' => $parameters
                        ]
                    ];

                    $skillNames[] = $name;

                } catch (Exception $e) {
                    $this->logError("获取技能 {$name} 定义失败: " . $e->getMessage());
                }
            }

            $this->logInfo("生成了 " . count($tools) . " 个工具定义");
            return $tools;

        } catch (Exception $e) {
            $this->logError("获取工具定义失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 执行技能
     */
    public function execute(string $name, array $arguments): string
    {
        try {
            // 验证技能是否存在
            if (!isset($this->skills[$name])) {
                throw new \InvalidArgumentException("技能不存在: {$name}");
            }

            // 检查是否可以执行
            if (!$this->canExecuteSkill($name)) {
                throw new \Exception("技能执行被限制: {$name}");
            }

            $skill = $this->skills[$name];

            // 验证参数
            $this->validateExecutionArguments($name, $arguments);

            // 执行技能
            $result = $skill->execute($arguments);

            $this->logInfo("成功执行技能: {$name}");
            return $result;

        } catch (Exception $e) {
            $this->logError("执行技能 {$name} 失败: " . $e->getMessage());
            throw new Exception("技能执行失败: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 检查技能是否可以执行
     */
    private function canExecuteSkill(string $name): bool
    {
        try {
            // 基本检查
            if (!isset($this->skills[$name])) {
                return false;
            }

            $skill = $this->skills[$name];
            // $meta = $skill->getMeta(); // 假设 OpenClawSkill 有这个方法

            // // 权限检查
            // if ($this->config['security_level'] === 'strict') {
            //     return in_array('execute', $meta['permissions'] ?? []);
            // }

            // 其他级别的检查...
            return true;

        } catch (Exception $e) {
            $this->logError("检查技能 {$name} 执行权限失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 验证工具参数
     */
    private function validateToolParameters(array $parameters): bool
    {
        try {
            // 基本的参数结构验证
            if (!isset($parameters['type']) || !isset($parameters['properties'])) {
                return false;
            }

            if (!is_array($parameters['properties'])) {
                return false;
            }

            // 验证每个属性
            foreach ($parameters['properties'] as $propName => $propDef) {
                if (!isset($propDef['type']) || !isset($propDef['description'])) {
                    return false;
                }

                if (!is_string($propName) || strlen($propName) > 50) {
                    return false;
                }
            }

            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 验证执行参数
     */
    private function validateExecutionArguments(string $skillName, array $arguments): void
    {
        // 基本的参数验证
        if (empty($arguments)) {
            throw new \InvalidArgumentException("执行参数不能为空");
        }

        // 检查参数类型
        foreach ($arguments as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                throw new \InvalidArgumentException("参数格式无效: {$key}");
            }
        }
    }

    /**
     * 记录信息
     */
    private function logInfo(string $message): void
    {
        $this->logMessage($message, 'INFO');
    }

    /**
     * 记录警告
     */
    private function logWarning(string $message): void
    {
        $this->logMessage($message, 'WARNING');
    }

    /**
     * 记录错误
     */
    private function logError(string $message): void
    {
        $this->logMessage($message, 'ERROR');
    }

    /**
     * 记录消息
     */
    private function logMessage(string $message, string $level): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}";
        
        if ($this->config['log_errors']) {
            error_log("[SkillManager] {$logEntry}");
        }
        
        // 限制日志大小
        if (count($this->errorLog) < 100) {
            $this->errorLog[] = $logEntry;
        }
    }

    /**
     * 处理临界错误
     */
    private function handleCriticalError(string $message, Exception $e): void
    {
        $errorMsg = "❌ {$message}: " . $e->getMessage();
        error_log("[SkillManager] CRITICAL: {$errorMsg}");
        
        // 清空技能缓存
        $this->skills = [];
        $this->isLoaded = false;
    }

    /**
     * 获取技能列表
     */
    public function getAvailableSkills(): array
    {
        if (!$this->isLoaded) {
            $this->loadSkills();
        }

        return array_keys($this->skills);
    }

    /**
     * 获取技能信息
     */
    public function getSkillInfo(string $name): ?array
    {
        if (!isset($this->skills[$name])) {
            return null;
        }

        try {
            $skill = $this->skills[$name];
            return [
                'name' => $skill->getName(),
                'description' => $skill->getDescription(),
                'parameters' => $skill->getParameters(),
                'status' => 'active'
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 重新加载技能
     */
    public function reloadSkills(): bool
    {
        try {
            $this->clearCache();
            $this->loadSkills();
            return true;
        } catch (Exception $e) {
            $this->logError("重新加载技能失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 清除缓存
     */
    private function clearCache(): void
    {
        $this->skills = [];
        $this->isLoaded = false;
        $this->errorLog = [];
    }

    /**
     * 获取管理器状态
     */
    public function getStatus(): array
    {
        return [
            'loaded_skills' => count($this->skills),
            'is_loaded' => $this->isLoaded,
            'skill_directory' => $this->config['skill_directory'],
            'error_count' => count($this->errorLog),
            'last_errors' => array_slice($this->errorLog, -5)
        ];
    }

    /**
     * 获取错误日志
     */
    public function getErrorLog(): array
    {
        return $this->errorLog;
    }

    /**
     * 清除错误日志
     */
    public function clearErrorLog(): void
    {
        $this->errorLog = [];
    }
}
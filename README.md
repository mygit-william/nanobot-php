# PHP-Nanobot 🤖

一个基于 Mini-OpenClaw 架构的 PHP 智能 Agent 系统，能够与多种大语言模型(LLM)交互并安全执行系统工具操作。

## ✨ 特性

- **多模型支持**: 无缝集成 Ollama、智谱AI、OpenAI、LongCat 等多种 LLM
- **安全执行**: 严格的命令过滤和权限控制，防止危险操作
- **长期记忆**: 自动保存对话历史，支持上下文感知
- **多通道**: 支持 CLI 终端和钉钉机器人
- **工具丰富**: 内置文件操作、bash 命令、编辑器等实用工具
- **协程优化**: 可选的 Swoole 协程支持，提升并发性能
- **中文友好**: 完善的中文编码处理

## 🏗️ 架构

```
nanobot-php/
├── bin/nanobot          # 主入口文件
├── src/
│   ├── Core/Agent.php   # 核心代理类
│   ├── LLM/             # 大语言模型适配器
│   ├── Channels/        # 通信通道
│   ├── Tools/           # 系统工具执行器
│   └── Skills/          # 技能管理器
├── storage/             # 数据存储
│   ├── AGENTS.md        # 系统提示词
│   └── memory/          # 长期记忆
├── config.json          # 配置文件
└── vendor/              # Composer 依赖
```

## 📋 要求

- PHP >= 7.4
- Composer
- curl 扩展
- mbstring 扩展（推荐）
- Swoole 扩展（可选，用于协程支持）

## 🚀 安装

1. **克隆项目**
```bash
git clone <repository-url>
cd nanobot-php
```

2. **安装依赖**
```bash
composer install
```

3. **配置项目**
```bash
cp config.json.example config.json
# 编辑 config.json，配置你的 API 密钥和模型参数
```

## 🛠️ 配置

编辑 `config.json` 配置文件：

```json
{
  "app": {
    "name": "PHP-Nanobot",
    "debug": true
  },
  "llm": {
    "default_provider": "zhipu",
    "providers": {
      "ollama": {
        "driver": "ollama",
        "base_url": "http://localhost:11434",
        "model": "qwen:7b"
      },
      "zhipu": {
        "driver": "zhipu",
        "base_url": "https://open.bigmodel.cn",
        "model": "glm-5",
        "api_key": "your-api-key-here"
      },
      "openai": {
        "driver": "openai",
        "api_key": "your-openai-key-here",
        "model": "gpt-3.5-turbo"
      }
    }
  },
  "channels": {
    "dingtalk": {
      "enabled": false,
      "app_key": "your-app-key",
      "app_secret": "your-app-secret",
      "webhook_url": "your-webhook-url"
    }
  }
}
```

## 💻 使用

### CLI 模式

```bash
php bin/nanobot cli
```

### 钉钉服务模式

```bash
php bin/nanobot serve
```

### 交互命令

- 输入 `exit` 退出程序
- 支持中文输入，自动编码转换
- 智能提示当前工作目录和系统信息

## 🔧 核心功能

### LLM 适配器系统

支持多种大语言模型，通过统一接口调用：

```php
$llmFactory = new LLMFactory($config['llm']);
$llm = $llmFactory->make(); // 使用默认模型
$response = $llm->chat($messages, $tools);
```

### 智能代理

```php
$agent = new Agent($llm);
$reply = $agent->chat('session-id', '用户输入', $messages);
```

### 安全命令执行

```php
$executor = new ShellExecutor();
$output = $executor->exec('ls -la'); // 只允许白名单命令
```

## 🔒 安全特性

- **命令白名单**: 只允许预定义的安全命令
- **黑名单过滤**: 阻止 `rm -rf /`, `dd`, `sudo`, `ssh` 等危险操作
- **输出限制**: 最大 1MB 输出防止恶意命令
- **超时控制**: 默认 30 秒超时保护
- **路径验证**: 防止目录遍历攻击
- **敏感信息清理**: 日志中自动屏蔽密码、API 密钥

## 📁 存储结构

- `storage/AGENTS.md`: 系统提示词和 Agent 行为准则
- `storage/memory/long_term_memory.json`: 长期记忆存储
- `storage/context/`: 会话上下文存储目录

## 🧪 开发

### 添加新的 LLM 适配器

1. 实现 `LLMInterface` 接口
2. 在 `LLMFactory` 中注册新的驱动类型

```php
class MyLLMAdapter implements LLMInterface {
    public function chat(array &$messages, array $tools = []): string {
        // 实现调用逻辑
    }
}
```

### 添加新的通信通道

实现 `ChannelInterface` 接口：

```php
class MyChannel implements ChannelInterface {
    public function getName(): string { return 'my-channel'; }
    public function receive(array $conservation = []): void { /* ... */ }
    public function send(string $sessionId, string $message): void { /* ... */ }
}
```

## 📄 许可证

MIT License

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

## 📞 支持

如有问题或建议，请在 GitHub Issues 中反馈。
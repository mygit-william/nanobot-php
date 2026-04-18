<?php

namespace App\Tools;

/**
 * 安全的Shell命令执行器
 * 提供多层次的命令安全性检查
 */
class ShellExecutor
{
    // 命令白名单 - 只允许这些命令执行
    private array $commandWhitelist = [
        // 文件操作
        'ls', 'dir', 'cat', 'head', 'tail', 'grep', 'find', 'file', 'stat',
        // 系统信息
        'pwd', 'whoami', 'hostname', 'uname', 'date', 'time', 'which', 'whereis',
        // 进程管理
        'ps', 'top', 'htop', 'kill', 'jobs', 'bg', 'fg', 'nohup',
        // 网络工具
        'ping', 'curl', 'wget', 'netstat', 'ss', 'ifconfig', 'ip', 'hostname',
        // 开发工具
        'git', 'composer', 'php', 'python', 'node', 'npm', 'yarn',
        // 文本处理
        'echo', 'printf', 'awk', 'sed', 'sort', 'uniq', 'cut', 'tr',
        // 压缩工具
        'tar', 'gzip', 'gunzip', 'zip', 'unzip',
        // 权限管理
        'chmod', 'chown', 'chgrp',
    ];

    // 命令黑名单 - 禁止这些命令执行
    private array $commandBlacklist = [
        'rm', 'rmdir', 'dd', 'mkfs', 'fdisk', 'format',
        'sudo', 'su', 'ssh', 'scp', 'sftp',
        'chroot', 'mount', 'umount', 'insmod', 'rmmod',
        'killall', 'pkill', 'shutdown', 'reboot', 'halt',
        '>', '>>', '<', '|', '&&', '||', ';', '$(', '`',
    ];

    // 参数黑名单 - 禁止包含这些内容的参数
    private array $paramBlacklist = [
        '..', '//', './', '$_', '$(',
        '`', '|', '>', '<', '>>', '<<',
        '&&', '||', ';', '\n', '\r',
    ];

    // 最大输出大小 (1MB)
    private const MAX_OUTPUT_SIZE = 1048576;

    // 命令执行超时时间 (秒)
    private int $timeout = 30;

    // 允许的命令路径
    private array $allowedPaths = ['/usr/bin', '/bin', '/usr/local/bin', '/usr/sbin', '/sbin'];

    /**
     * 执行命令并返回输出
     */
    public function exec(string $command): string
    {
        // 1. 基础安全检查
        if (!$this->basicSecurityCheck($command)) {
            return "错误：命令未通过基础安全检查";
        }

        // 2. 解析命令
        $parsedCommand = $this->parseCommand($command);
        if ($parsedCommand === null) {
            return "错误：无法解析命令";
        }

        // 3. 白名单检查
        if (!$this->checkWhitelist($parsedCommand['command'])) {
            return "错误：命令不在白名单中 - {$parsedCommand['command']}";
        }

        // 4. 黑名单检查
        if ($this->checkBlacklist($parsedCommand['command'])) {
            return "错误：命令在黑名单中 - {$parsedCommand['command']}";
        }

        // 5. 参数安全检查
        if (!$this->checkParameters($parsedCommand['args'])) {
            return "错误：参数包含非法内容";
        }

        // 6. 路径安全检查
        if (!$this->checkCommandPath($parsedCommand['command'])) {
            return "错误：命令路径不在允许范围内";
        }

        // 7. 构建安全命令
        $safeCommand = $this->buildSafeCommand($parsedCommand);

        // 8. 执行命令并捕获输出
        return $this->executeSafely($safeCommand);
    }

    /**
     * 基础安全检查
     */
    private function basicSecurityCheck(string $command): bool
    {
        // 空命令检查
        if (empty(trim($command))) {
            return false;
        }

        // 命令长度限制 (防止过长的命令)
        if (strlen($command) > 500) {
            return false;
        }

        // 特殊字符检查
        if (preg_match('/[<>|;&$`]/', $command)) {
            return false;
        }

        // 检查是否包含环境变量
        if (preg_match('/\$\(.*\)/', $command)) {
            return false;
        }

        return true;
    }

    /**
     * 解析命令
     */
    private function parseCommand(string $command): ?array
    {
        $command = trim($command);

        // 提取命令和参数
        $parts = preg_split('/\s+/', $command, 2);
        if (empty($parts[0])) {
            return null;
        }

        return [
            'command' => $parts[0],
            'args' => $parts[1] ?? '',
        ];
    }

    /**
     * 检查命令白名单
     */
    private function checkWhitelist(string $command): bool
    {
        // 检查完整命令
        if (in_array($command, $this->commandWhitelist, true)) {
            return true;
        }

        // 检查命令别名
        return $this->checkCommandAliases($command);
    }

    /**
     * 检查命令别名
     */
    private function checkCommandAliases(string $command): bool
    {
        $aliases = [
            'll' => 'ls',
            'la' => 'ls',
            'l' => 'ls',
            'h' => 'history',
            'c' => 'clear',
        ];

        return isset($aliases[$command]) && $this->checkWhitelist($aliases[$command]);
    }

    /**
     * 检查命令黑名单
     */
    private function checkBlacklist(string $command): bool
    {
        return in_array($command, $this->commandBlacklist, true);
    }

    /**
     * 检查参数安全性
     */
    private function checkParameters(string $args): bool
    {
        if (empty($args)) {
            return true;
        }

        // 检查参数黑名单
        foreach ($this->paramBlacklist as $blacklisted) {
            if (strpos($args, $blacklisted) !== false) {
                return false;
            }
        }

        // 检查路径遍历
        if (preg_match('/\.\.(\/|$)/', $args)) {
            return false;
        }

        // 检查特殊权限
        if (preg_match('/sudo\s+|su\s+/', $args)) {
            return false;
        }

        return true;
    }

    /**
     * 检查命令路径
     */
    private function checkCommandPath(string $command): bool
    {
        // 如果是绝对路径，检查是否在允许的路径中
        if (strpos($command, '/') === 0) {
            foreach ($this->allowedPaths as $path) {
                if (strpos($command, $path) === 0) {
                    return true;
                }
            }
            return false;
        }

        // 对于非绝对路径，使用which查找实际路径
        $result = shell_exec("which {$command} 2>/dev/null");
        if (empty($result)) {
            return false;
        }

        $actualPath = trim($result);
        foreach ($this->allowedPaths as $path) {
            if (strpos($actualPath, $path) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 构建安全命令
     */
    private function buildSafeCommand(array $parsedCommand): string
    {
        $command = escapeshellcmd($parsedCommand['command']);

        if (!empty($parsedCommand['args'])) {
            $args = escapeshellarg($parsedCommand['args']);
            // 移除多余的引号，因为escapeshellarg已经添加了
            $args = trim($args, "'\"");
            $command .= ' ' . $args;
        }

        // 添加超时限制和输出限制
        $command = "timeout {$this->timeout} {$command} 2>&1";

        return $command;
    }

    /**
     * 安全执行命令
     */
    private function executeSafely(string $command): string
    {
        // 使用proc_open来更好地控制执行
        $descriptorspec = [
            0 => ['pipe', 'r'],  // 标准输入
            1 => ['pipe', 'w'],  // 标准输出
            2 => ['pipe', 'w'],  // 标准错误
        ];

        $process = proc_open($command, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            return "错误：无法启动命令执行";
        }

        // 设置超时
        stream_set_timeout($pipes[1], $this->timeout);
        stream_set_timeout($pipes[2], $this->timeout);

        // 读取输出
        $output = '';
        $stderr = '';

        // 非阻塞读取
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;

        while (!empty($read)) {
            $ready = stream_select($read, $write, $except, $this->timeout);

            if ($ready === false) {
                break;
            }

            foreach ($read as $stream) {
                if (feof($stream)) {
                    continue;
                }

                $data = fread($stream, 8192);
                if ($data === false) {
                    continue;
                }

                if ($stream === $pipes[1]) {
                    $output .= $data;
                } else {
                    $stderr .= $data;
                }

                // 检查输出大小限制
                if (strlen($output) > self::MAX_OUTPUT_SIZE) {
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_terminate($process);
                    return substr($output, 0, self::MAX_OUTPUT_SIZE) . "\n... (输出被截断，超过1MB限制)";
                }
            }

            // 检查进程是否结束
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
        }

        // 关闭所有管道
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        // 获取退出码
        $exitCode = proc_close($process);

        // 如果有错误输出，合并到主输出
        if (!empty($stderr)) {
            $output .= "\nSTDERR: {$stderr}";
        }

        // 处理超时情况
        if ($exitCode === 124) {
            return "错误：命令执行超时 (超过 {$this->timeout} 秒)";
        }

        return $output;
    }

    /**
     * 添加命令到白名单
     */
    public function addToWhitelist(string $command): void
    {
        if (!in_array($command, $this->commandWhitelist, true)) {
            $this->commandWhitelist[] = $command;
        }
    }

    /**
     * 从白名单移除命令
     */
    public function removeFromWhitelist(string $command): void
    {
        $key = array_search($command, $this->commandWhitelist, true);
        if ($key !== false) {
            unset($this->commandWhitelist[$key]);
        }
    }

    /**
     * 设置超时时间
     */
    public function setTimeout(int $timeout): void
    {
        if ($timeout > 0 && $timeout <= 300) {
            $this->timeout = $timeout;
        }
    }

    /**
     * 获取当前配置
     */
    public function getConfig(): array
    {
        return [
            'whitelist' => $this->commandWhitelist,
            'blacklist' => $this->commandBlacklist,
            'timeout' => $this->timeout,
            'max_output_size' => self::MAX_OUTPUT_SIZE,
            'allowed_paths' => $this->allowedPaths,
        ];
    }

    /**
     * 重置为默认配置
     */
    public function resetToDefaults(): void
    {
        $this->commandWhitelist = [
            'ls', 'dir', 'cat', 'head', 'tail', 'grep', 'find', 'file', 'stat',
            'pwd', 'whoami', 'hostname', 'uname', 'date', 'time', 'which', 'whereis',
            'ps', 'top', 'htop', 'kill', 'jobs', 'bg', 'fg', 'nohup',
            'ping', 'curl', 'wget', 'netstat', 'ss', 'ifconfig', 'ip', 'hostname',
            'git', 'composer', 'php', 'python', 'node', 'npm', 'yarn',
            'echo', 'printf', 'awk', 'sed', 'sort', 'uniq', 'cut', 'tr',
            'tar', 'gzip', 'gunzip', 'zip', 'unzip',
            'chmod', 'chown', 'chgrp',
        ];

        $this->commandBlacklist = [
            'rm', 'rmdir', 'dd', 'mkfs', 'fdisk', 'format',
            'sudo', 'su', 'ssh', 'scp', 'sftp',
            'chroot', 'mount', 'umount', 'insmod', 'rmmod',
            'killall', 'pkill', 'shutdown', 'reboot', 'halt',
            '>', '>>', '<', '|', '&&', '||', ';', '$(', '`',
        ];

        $this->timeout = 30;
    }
}
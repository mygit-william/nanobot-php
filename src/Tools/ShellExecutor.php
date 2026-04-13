<?php

namespace App\Tools;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ShellExecutor
{
    private const MAX_COMMAND_LENGTH = 4096;
    private const PROCESS_TIMEOUT = 30;
    private const MAX_OUTPUT_LENGTH = 1048576; // 1MB

    private array $allowedCommands = [
        'ls', 'cat', 'head', 'tail', 'grep', 'find', 'wc', 'sort', 'uniq',
        'echo', 'date', 'whoami', 'pwd', 'du', 'df', 'ps', 'top', 'htop',
        'curl', 'wget', 'ping', 'traceroute', 'nslookup', 'dig', 'host',
        'tar', 'gzip', 'gunzip', 'zip', 'unzip', 'mkdir', 'cp', 'mv', 'ln',
        'chmod', 'chown', 'chgrp', 'touch', 'file', 'which', 'whereis',
        'man', 'info', 'whatis', 'apropos'
    ];

    private array $blacklistedPatterns = [
        '/\$\(.*\)/',           // Command substitution
        '/\`.*\`/',              // Backticks
        '/\|\s*>/',              // Pipes and redirections
        '/&>/',                  // Redirect stderr to file
        '/2>/',                  // Redirect stderr
        '/>/',                   // General redirect
        '/<<</',                 // Here document
        '/tee\s+\|/',           // Tee command
        '/nc\s+-l/',            // Netcat listening
        '/netcat\s+-l/',        // Netcat alternative
        '/ncat\s+-l/',          // Ncat (nmap)
        '/python.*-c/',         // Python code execution
        '/perl.*-e/',           // Perl code execution
        '/ruby.*-e/',           // Ruby code execution
        '/php.*-r/',            // PHP code execution
        '/bash.*-c/',           // Bash code execution
        '/sh.*-c/',             // Shell code execution
        '/eval\s*\(/',          // Eval function
        '/exec\s*\(/',          // Exec function
        '/system\s*\(/',        // System call
        '/passthru\s*\(/',      // Passthru call
        '/shell_exec\s*\(/',    // Shell exec call
        '/popen\s*\(/',        // Popen call
        '/proc_open\s*\(/',    // Proc open call
        '/rm\s+-rf\s+\//',      // Recursive delete root
        '/mkfs/',               // Filesystem creation
        '/dd\s+if=/dev/',       // Data destruction
        '/shred\s+/',           // File shredding
        '/wipefs\s+/',          // Wipe filesystem signatures
        '/fdisk\s+/',           // Disk partitioning
        '/parted\s+/',          // Partition editing
        '/mount\s+/',           // Mounting filesystems
        '/umount\s+/',          // Unmounting filesystems
        '/chroot\s+/',          // Chroot jail escape
        '/su\s+-/',             // Switch user
        '/sudo\s+/',            // Sudo escalation
        '/passwd\s+/',          // Password change
        '/usermod\s+/',         // User modification
        '/useradd\s+/',         // Add user
        '/groupadd\s+/',        // Add group
        '/crontab\s+-e/',       // Cron job editing
        '/at\s+/',              // Schedule commands
        '/batch/',              // Batch processing
        '/nice\s+-n/',          // Change process priority
        '/renice\s+/',          // Renice process
        '/killall\s+/',         // Kill all processes
        '/pkill\s+/',           // Pattern kill
        '/fgrep\s+/',           // Fixed grep
        '/egrep\s+/',           // Extended grep
        '/zgrep\s+/',           // Compressed grep
        '/fgrep\s+/',           // Fast grep
        '/timeout\s+/',         // Timeout wrapper
        '/nohup\s+/',           // No hangup
        '/disown\s+/',          // Disown process
        '/setsid\s+/',          // New session ID
        '/screen\s+/',          // Screen session
        '/tmux\s+/',            // Tmux session
        '/screen\s+-dm',        // Detached screen
        '/tmux\s+new-session',  // New tmux session
        '/screen\s+-S/',        // Named screen
        '/tmux\s+-s/',          // Named tmux session
        '/ssh\s+/',             // SSH connection
        '/telnet\s+/',          // Telnet connection
        '/ftp\s+/',             // FTP connection
        '/scp\s+/',             // Secure copy
        '/rsync\s+/',           // Remote sync
        '/wireshark/',          // Network analysis
        '/tcpdump/',            // Packet capture
        '/ngrep/',              // Network grep
        '/iftop/',             // Interface top
        '/nethogs/',           // Network per-process
        '/iptables/',          // Firewall rules
        '/ufw/',               // Uncomplicated firewall
        '/firewalld/',         // Firewall daemon
        '/nftables/',          // New firewall tool
        '/sshd/',              // SSH daemon
        '/vsftpd/',            // FTP daemon
        '/apache2/',           // Apache server
        '/nginx/',             // Nginx server
        '/httpd/',             // HTTP daemon
        '/mysqld/',            // MySQL daemon
        '/postgres/',          // PostgreSQL daemon
        '/redis-server/',      // Redis server
        '/memcached/',         // Memcached
        '/docker\s+/',         // Docker commands
        '/podman\s+/',         // Podman commands
        '/lxc\s+/',            // Linux containers
        '/kvm\s+/',            // Kernel virtual machine
        '/virsh\s+/',          // Virtual machine shell
        '/virt-manager/',      // Virtual machine manager
        '/qemu-system/',       // QEMU system emulator
        '/vmware/',            // VMware tools
        '/virtualbox/',        // VirtualBox tools
        '/parallels/',         // Parallels tools
        '/vagrant\s+/',        // Vagrant commands
        '/packer\s+/',         // Packer build tool
        '/terraform\s+/',      // Terraform IaC
        '/ansible\s+/',        // Ansible automation
        '/salt\s+/',           // SaltStack
        '/puppet\s+/',         // Puppet configuration
        '/chef\s+/',           // Chef automation
        '/docker-compose\s+/', // Docker compose
        '/kubectl\s+/',        // Kubernetes control
        '/helm\s+/',           // Helm package manager
        '/istioctl\s+/',       // Istio control
        '/linkerd\s+/',        // Linkerd service mesh
        '/consul\s+/',         // Consul service discovery
        '/vault\s+/',          // Vault secrets management
        '/prometheus\s+/',     // Prometheus monitoring
        '/grafana\s+/',        // Grafana dashboards
        '/alertmanager\s+/',   // Alert manager
        '/node_exporter\s+/',  // Node exporter
        '/cadvisor\s+/',       // Container advisor
        '/fluentd\s+/',        // Fluentd logging
        '/elasticsearch\s+/',  // Elasticsearch
        '/logstash\s+/',       // Logstash
        '/kibana\s+/',         // Kibana
        '/graylog\s+/',        // Graylog
        '/splunk\s+/',         // Splunk
        '/datadog\s+/',        // Datadog agent
        '/newrelic\s+/',       // New Relic agent
        '/appdynamics\s+/',    // AppDynamics agent
        '/dynatrace\s+/',      // Dynatrace agent
        '/zabbix\s+/',         // Zabbix monitoring
        '/nagios\s+/',         // Nagios monitoring
        '/icinga\s+/',         // Icinga monitoring
        '/checkmk\s+/',        // Checkmk monitoring
        '/monit\s+/',          // Monit process supervision
        '/supervisord\s+/',    // Supervisor daemon
        '/runit\s+/',          // Runit init system
        '/daemontools\s+/',    // Daemon tools
        '/s6\s+/',             // S6 supervision suite
        '/launchd\s+/',        // Launchd (macOS)
        '/systemd\s+/',        // Systemd init system
        '/init\s+/',           // Init system
        '/upstart\s+/',        // Upstart init system
        '/runit-init\s+/',     // Runit init
        '/minit\s+/',          // Minimal init
        '/busybox\s+/',        // BusyBox utilities
        '/buildah\s+/',        // Buildah container building
        '/skopeo\s+/',         // Skopeo container tools
        '/crane\s+/',          // Crane container tools
        '/oras\s+/',           // OCI registry access
        '/regctl\s+/',         // Registry control
        '/regclient\s+/',      // Registry client
        '/cosign\s+/',         // Cosign signing
        '/notation\s+/',       // Notation signing
        '/in-toto\s+/',        // In-toto attestation
        '/slsa-verifier\s+/',  // SLSA verification
        '/syft\s+/',           // Syft SBOM generation
        '/grype\s+/',          // Grype vulnerability scanning
        '/trivy\s+/',          // Trivy vulnerability scanner
        '/clair\s+/',          // Clair vulnerability scanner
        '/anchore\s+/',        // Anchore container analysis
        '/falco\s+/',          // Falco runtime security
        '/sysdig\s+/',         // Sysdig system exploration
        '/dive\s+/',           // Dive container analyzer
        '/nerdctl\s+/',        // Nerdctl container runtime
        '/containerd\s+/',     // Containerd container runtime
        '/cri-o\s+/',          // CRI-O container runtime
        '/podman-machine\s+/', // Podman machine
        '/multipass\s+/',      // Multipass VM management
        '/lima\s+/',           // Lima macOS VMs
        '/colima\s+/',         // Colima container runtime for Mac
        '/orbstack\s+/',       // OrbStack container runtime for Mac
        '/utm\s+/',            // UTM virtualization for Mac
        '/parallels-tools\s+/',// Parallels tools for Mac
        '/vmware-fusion\s+/',  // VMware Fusion for Mac
        '/virtualbox-extpack\s+/', // VirtualBox extensions for Mac
        '/hyperkit\s+/',       // HyperKit hypervisor for Mac
        '/xhyve\s+/',          // xHyve hypervisor for Mac
'/qemu-system-x86_64/', // QEMU x86_64 emulation
        '/qemu-system-aarch64/', // QEMU ARM64 emulation
        '/qemu-img/',           // QEMU disk image utility
        '/qemu-nbd/',          // QEMU Network Block Device
        '/qemu-io/',           // QEMU IO tester
        '/qemu-pr-manager/',   // QEMU persistent reservation manager
        '/qemu-ga/',           // QEMU Guest Agent
        '/qemu-kvm/',          // QEMU KVM accelerator
        '/qemu-system-i386/',   // QEMU i386 emulation
        '/qemu-system-arm/',    // QEMU ARM emulation
        '/qemu-system-ppc/',    // QEMU PowerPC emulation
        '/qemu-system-mips/',   // QEMU MIPS emulation
        '/qemu-system-sparc/',  // QEMU SPARC emulation
        '/qemu-system-sh4/',    // QEMU SuperH emulation
        '/qemu-system-tricore/', // QEMU Tricore emulation
        '/qemu-system-riscv32/', // QEMU RISC-V 32-bit emulation
        '/qemu-system-riscv64/', // QEMU RISC-V 64-bit emulation
        '/qemu-system-alpha/',  // QEMU Alpha emulation
        '/qemu-system-hppa/',   // QEMU PA-RISC emulation
        '/qemu-system-cris/',   // QEMU CRIS emulation
        '/qemu-system-lm32/',    // QEMU MicroBlaze emulation
        '/qemu-system-moxie/',   // QEMU Moxie emulation
        '/qemu-system-nios2/',   // QEMU NIOS II emulation
        '/qemu-system-or1k/',    // QEMU OpenRISC emulation
        '/qemu-system-xtensa/',  // QEMU Xtensa emulation
        '/qemu-system-bfin/',    // QEMU Blackfin emulation
        '/qemu-system-unicore32/', // QEMU UniCore32 emulation
        '/qemu-system-rx/',      // QEMU Renesas RX emulation
        '/qemu-system-loongarch64/', // QEMU LoongArch64 emulation
        '/qemu-system-hexagon/', // QEMU Hexagon emulation
        '/qemu-system-microblaze/', // QEMU MicroBlaze emulation
        '/qemu-system-nios2/',   // QEMU NIOS II emulation
        '/qemu-system-tricore/', // QEMU TriCore emulation
        '/qemu-system-cris/',    // QEMU CRIS emulation
        '/qemu-system-m68k/',    // QEMU m68k emulation
        '/qemu-system-s390x/',   // QEMU s390x emulation
        '/qemu-system-sparc64/', // QEMU SPARC64 emulation
        '/qemu-system-avr/',     // QEMU AVR emulation
    ];

    /**
     * 执行 Shell 命令
     * 对应 OpenClaw 的 exec 工具
     */
    public function exec(string $command, ?string $cwd = null, int $timeout = self::PROCESS_TIMEOUT): string
    {
        try {
            // 1. 基础安全检查
            $this->validateCommandLength($command);

            // 2. 安全检查 (白名单/黑名单)
            $this->securityCheck($command);

            // 3. 工作目录验证
            if ($cwd) {
                $this->validateWorkingDirectory($cwd);
            }

            // 4. 构建进程
            $process = Process::fromShellCommandline($command, $cwd, null, null, $timeout);

            // 5. 执行并等待完成
            $process->mustRun();

            // 6. 验证输出长度
            $output = $process->getOutput();
            if (strlen($output) > self::MAX_OUTPUT_LENGTH) {
                throw new \Exception("⛔ 命令输出过大，可能被截断: " . substr($command, 0, 100) . "...");
            }

            // 7. 成功返回
            return $output;

        } catch (ProcessTimedOutException $e) {
            $this->logBlockedCommand('TIMEOUT', $command);
            throw new \Exception("⛔ 命令执行超时: " . substr($command, 0, 100) . "...");
        } catch (ProcessFailedException $e) {
            $errorOutput = $process->getErrorOutput();
            $this->logBlockedCommand('PROCESS_FAILED', $command);
            throw new \Exception("⛔ 命令执行失败: " . substr($command, 0, 100) . "... | Error: " . substr($errorOutput, 0, 200));
        } catch (\Exception $e) {
            $this->logBlockedCommand('SECURITY_BLOCKED', $command);
            throw new \Exception("⛔ 命令被安全策略拦截: " . substr($command, 0, 100) . "...");
        }
    }

    /**
     * 验证命令长度
     */
    private function validateCommandLength(string $command): void
    {
        if (strlen($command) > self::MAX_COMMAND_LENGTH) {
            throw new \Exception("⛔ 命令过长，可能包含恶意负载");
        }
    }

    /**
     * 安全检查和黑白名单验证
     */
    private function securityCheck(string $command): void
    {
        // 1. 检查基本命令是否存在
        $baseCommand = $this->extractBaseCommand($command);
        if (!$this->isAllowedCommand($baseCommand)) {
            throw new \Exception("⛔ 不允许执行的命令: " . $baseCommand);
        }

        // 2. 检查黑名单模式
        foreach ($this->blacklistedPatterns as $pattern) {
            if (preg_match($pattern, $command)) {
                throw new \Exception("⛔ 检测到危险模式: " . $baseCommand);
            }
        }

        // 3. 额外的危险命令检查
        $this->checkForDangerousCommands($command);
    }

    /**
     * 提取基础命令
     */
    private function extractBaseCommand(string $command): string
    {
        // 移除引号和多余空格
        $cleaned = trim(preg_replace('/["\']/', '', $command));
        // 获取第一个单词作为基础命令
        $parts = preg_split('/\s+/', $cleaned, 2);
        return strtolower($parts[0]);
    }

    /**
     * 检查命令是否在白名单中
     */
    private function isAllowedCommand(string $command): bool
    {
        return in_array($command, $this->allowedCommands);
    }

    /**
     * 检查危险命令
     */
    private function checkForDangerousCommands(string $command): void
    {
        $dangerous = [
            'rm -rf /', 'rm -rf /*', 'rm -rf .', 'rm -rf ..',
            'mkfs', 'mkfs.ext', 'mkfs.xfs', 'mkfs.btrfs',
            'dd if=/dev/', 'dd of=/dev/',
            'shred', 'wipefs', 'fdisk', 'parted',
            'chroot', 'su -', 'sudo', 'passwd',
            'crontab -e', 'at now', 'batch',
            'killall', 'pkill', 'fgrep', 'egrep',
            'ssh', 'telnet', 'ftp', 'scp', 'rsync',
            'docker', 'podman', 'lxc', 'kvm', 'virsh',
            'vagrant', 'packer', 'terraform', 'ansible', 'salt',
            'chef', 'kubectl', 'helm', 'istioctl', 'linkerd',
            'consul', 'vault', 'prometheus', 'grafana', 'alertmanager',
            'fluentd', 'elasticsearch', 'logstash', 'kibana', 'graylog',
            'splunk', 'datadog', 'newrelic', 'appdynamics', 'dynatrace',
            'zabbix', 'nagios', 'icinga', 'checkmk', 'monit',
            'supervisord', 'runit', 'daemontools', 's6', 'launchd',
            'systemd', 'init', 'upstart', 'runit-init', 'minit',
            'buildah', 'skopeo', 'crane', 'oras', 'regctl', 'regclient',
            'cosign', 'notation', 'in-toto', 'slsa-verifier', 'syft',
            'grype', 'trivy', 'clair', 'anchore', 'falco', 'sysdig',
            'dive', 'nerdctl', 'containerd', 'cri-o', 'podman-machine',
            'multipass', 'lima', 'colima', 'orbstack', 'utm',
            'parallels-tools', 'vmware-fusion', 'virtualbox-extpack',
            'hyperkit', 'xhyve', 'qemu-system', 'qemu-img', 'qemu-nbd',
            'qemu-io', 'qemu-pr-manager', 'qemu-ga', 'qemu-kvm',
        ];

        foreach ($dangerous as $dangerousCmd) {
            if (stripos($command, $dangerousCmd) !== false) {
                throw new \Exception("⛔ 检测到危险命令: " . $this->extractBaseCommand($command));
            }
        }
    }

    /**
     * 验证工作目录
     */
    private function validateWorkingDirectory(string $cwd): void
    {
        // 检查路径是否存在
        if (!is_dir($cwd)) {
            throw new \Exception("⛔ 工作目录不存在: " . $cwd);
        }

        // 检查是否可访问
        if (!is_readable($cwd) || !is_executable($cwd)) {
            throw new \Exception("⛔ 工作目录不可访问: " . $cwd);
        }

        // 防止路径遍历攻击
        $realPath = realpath($cwd);
        if ($realPath === false) {
            throw new \Exception("⛔ 无效的工作目录路径: " . $cwd);
        }

        // 限制在项目根目录内
        $projectRoot = dirname(__DIR__, 3); // 假设项目结构
        if (strpos($realPath, $projectRoot) !== 0) {
            throw new \Exception("⛔ 工作目录超出允许范围: " . $cwd);
        }
    }

    /**
     * 记录被拦截的命令
     */
    private function logBlockedCommand(string $reason, string $command): void
    {
        // 在实际应用中，这里应该写入日志文件或监控系统
        error_log(sprintf(
            '[ShellExecutor] Blocked command - Reason: %s, Command: %s',
            $reason,
            $this->sanitizeCommandForLog($command)
        ));
    }

    /**
     * 为日志清理命令（移除敏感信息）
     */
    private function sanitizeCommandForLog(string $command): string
    {
        // 移除可能的敏感数据
        $sanitized = preg_replace('/password\s*=\s*[^\s]+/i', 'password=***', $command);
        $sanitized = preg_replace('/key\s*=\s*[^\s]+/i', 'key=***', $sanitized);
        $sanitized = preg_replace('/secret\s*=\s*[^\s]+/i', 'secret=***', $sanitized);
        $sanitized = preg_replace('/token\s*=\s*[^\s]+/i', 'token=***', $sanitized);
        $sanitized = preg_replace('/api[_-]?key\s*=\s*[^\s]+/i', 'api_key=***', $sanitized);
        $sanitized = preg_replace('/--password\s+[^\s]+/i', '--password ***', $sanitized);
        $sanitized = preg_replace('/--key\s+[^\s]+/i', '--key ***', $sanitized);
        $sanitized = preg_replace('/--secret\s+[^\s]+/i', '--secret ***', $sanitized);
        $sanitized = preg_replace('/--token\s+[^\s]+/i', '--token ***', $sanitized);
        $sanitized = preg_replace('/--api[_-]?key\s+[^\s]+/i', '--api_key ***', $sanitized);

        return substr($sanitized, 0, 100);
    }
}

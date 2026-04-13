<?php

use Swoole\Coroutine;

// --- 关键步骤：启用协程 Hook，这会使 cURL 变得非阻塞 ---
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

class LoadingAnimation {
    private $isRunning = false;
    private $frames = ['-', '\\', '|', '/'];
    private $currentFrameIndex = 0;
    private $message = '';

    public function __construct(string $message = 'Loading') {
        $this->message = $message;
    }

    public function start() {
        
        if ($this->isRunning) {
            return;
        }
        $this->isRunning = true;

        go(function () {
            while ($this->isRunning) {
                echo "\r{$this->message} {$this->frames[$this->currentFrameIndex]}";
                $this->currentFrameIndex = ($this->currentFrameIndex + 1) % count($this->frames);
                co::sleep(0.2); // 非阻塞休眠
            }
            echo "\r" . str_repeat(' ', strlen($this->message) + 5) . "\r";
        });
    }

    public function stop() {
        $this->isRunning = false;
        co::sleep(0.3); // 让动画协程有机会退出
    }
}

function makeCurlRequestWithHook() {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://www.baidu1.com/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 设置超时

    // --- 因为启用了 Hook，这里的 curl_exec 在 Swoole 环境下是非阻塞的！ ---
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    return [$response, $httpCode, $error];
}

function main() {
    $animation = new LoadingAnimation("正在用 cURL 请求 baidu.com (Hooked)");

    $animation->start();

    try {
        list($body, $httpCode, $error) = makeCurlRequestWithHook();

        $animation->stop();

        if ($error) {
            echo "cURL 错误: $error\n";
        } elseif ($httpCode === 200 && $body) {
            echo "cURL 请求成功！\n";
            echo "状态码: $httpCode\n";
            echo "响应内容 (前200字符): " . mb_substr($body, 0, 200, 'utf-8') . "...\n";
        } else {
            echo "cURL 请求失败！\n";
            echo "状态码: $httpCode\n";
        }
    } catch (Exception $e) {
        $animation->stop();
        echo "请求出错: " . $e->getMessage() . "\n";
    }
}

Coroutine::create('main');
Swoole\Event::wait();
<?php
/**
 * PHP命令行转圈圈网络请求Demo
 * 在命令行中显示加载动画的同时进行网络请求
 */

class SpinnerDemo
{
    private $spinner_chars = ['|', '/', '-', '\\'];
    private $current_spinner = 0;
    private $running = false;

    /**
     * 显示转圈圈动画
     */
    private function showSpinner($message = 'Loading')
    {
        if ($this->running) {
            // 清除上一行
            echo "\r" . str_repeat(' ', strlen($message) + 5) . "\r";
        }
        
        echo $message . ' ' . $this->spinner_chars[$this->current_spinner] . ' ';
        $this->current_spinner = ($this->current_spinner + 1) % count($this->spinner_chars);
        $this->running = true;
    }

    /**
     * 执行网络请求并显示加载动画
     */
    public function fetchUrl($url, $timeout = 30)
    {
        $this->running = false;
        $this->current_spinner = 0;
        
        // 创建后台进程来显示spinner
        $spinner_process = null;
        
        // 使用stream_select来模拟异步spinner
        $start_time = time();
        $last_spinner_update = 0;
        
        echo "开始请求: $url\n";
        
        // 初始化cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-Spinner-Demo/1.0');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // ⚠️ 仅测试环境使用
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);  // 关闭主机名验证
        // 设置进度回调函数
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $download_size, $downloaded, $upload_size, $uploaded) use (&$last_spinner_update) {
            $now = microtime();
            if (1||$now - $last_spinner_update >= 1) {
                // 显示spinner
                echo "\r" . str_repeat(' ', 50) . "\r";
                 $spinner_chars =['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];;
                static $spinner_index = 0;
                echo "ai思考中... " . $spinner_chars[$spinner_index % (count($spinner_chars)-1)] . " ".$now;
                $spinner_index++;
                $last_spinner_update = $now;
            }
            return 0;
        });
        
        // 执行请求
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        // 清除spinner行
        echo "\r" . str_repeat(' ', 50) . "\r";
        
        if ($result === false) {
            echo "❌ 请求失败: $error\n";
            return false;
        }
        
        echo "✅ 请求成功! HTTP状态码: $http_code\n";
        echo "📦 响应大小: " . strlen($result) . " 字节\n";
        
        return $result;
    }

    /**
     * 简单的spinner演示
     */
    public function simpleSpinnerDemo($duration = 5)
    {
        echo "🎬 开始spinner演示 ($duration 秒)...\n";
        
        $end_time = time() + $duration;
        while (time() < $end_time) {
            $this->showSpinner('处理中');
            usleep(100000); // 100ms
        }
        
        echo "\n✅ 演示完成!\n";
    }

    /**
     * 模拟长时间运行的任务
     */
    public function simulateLongTask($task_name = '任务', $duration = 10)
    {
        echo "🔄 开始$task_name...\n";
        
        $end_time = time() + $duration;
        while (time() < $end_time) {
            $this->showSpinner($task_name);
            usleep(100000); // 100ms
        }
        
        echo "\n✅ $task_name 完成!\n";
    }
}

// 使用示例
if (php_sapi_name() === 'cli') {
    $demo = new SpinnerDemo();
    
    echo "🎯 PHP命令行转圈圈网络请求Demo\n";
    echo str_repeat('=', 50) . "\n\n";
    
    // 演示1: 简单的spinner
    // $demo->simpleSpinnerDemo(3);
    echo "\n";
    
    // 演示2: 模拟长时间任务
    // $demo->simulateLongTask('数据同步', 5);
    echo "\n";
    
    // 演示3: 实际网络请求
    echo "🌐 开始网络请求演示...\n";
    // $response = $demo->fetchUrl('https://httpbin.org/delay/3');
    
    // if ($response) {
    //     echo "\n📋 响应预览 (前200字符):\n";
    //     echo substr($response, 0, 200) . "...\n";
    // }
    
    // echo "\n🎉 Demo演示完成!\n";
} else {
    echo "此脚本需要在命令行中运行: php spinner_demo.php\n";
}
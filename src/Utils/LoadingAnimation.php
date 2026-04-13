<?php
namespace App\Utils;

class LoadingAnimation
{
    private $isRunning = false;
    private $frames = [
        'progress_dots' => ['●○○○○○○○○○', '●●○○○○○○○○', '●●●○○○○○○○', '●●●●○○○○○○', '●●●●●○○○○○', '●●●●●●○○○○', '●●●●●●●○○○', '●●●●●●●●○○', '●●●●●●●●●○', '●●●●●●●●●●'],
        'spin_chars' => ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'],
        'spin_boxes' => ['◐', '◓', '◑', '◒'],
        'spin_pipes' => ['|', '/', '-', '\\'],
        'progress_brackets' => ['[>          ]', '[=>         ]', '[==>        ]', '[===>       ]', '[====>      ]', '[=====>     ]', '[======>    ]', '[=======>   ]', '[========>  ]', '[=========> ]', '[==========>]']
    ];
    private $currentFrameIndex = 0;
    private $message = '';
    private $handle; // 用于存储动画协程的ID
    private $start;

    public function __construct(string $message = 'Loading')
    {
        $this->message = $message;
    }

    /**
     * 启动加载动画
     */
    public function start()
    {
        if (!extension_loaded('swoole'))
            return;
        if ($this->isRunning) {
            return;
        }
        $this->start=microtime(true);
        $this->isRunning = true;

        // 创建一个新协程来运行动画
        $this->handle = go(function () {
            $frames=$this->frames['spin_chars'];
            while ($this->isRunning) {
                // echo time();
                // 打印当前帧，不换行，并移动光标到行首
                echo "\r{$this->message} {$frames[$this->currentFrameIndex]}    ".'时间:' . round((microtime(true) - $this->start) ,3) . "s";
                $this->currentFrameIndex = ($this->currentFrameIndex + 1) % count($frames);

                // 关键：使用协程的 sleep，不会阻塞其他协程
                \co::sleep(0.1);
            }
            // 动画结束时清除当前行并换行
            echo "\r" . str_repeat(' ', strlen($this->message) . 5) . "\r";
        });
    }

    /**
     * 停止加载动画
     */
    public function stop()
    {
        if (!extension_loaded('swoole'))
            return;
        if (!$this->isRunning) {
            return;
        } 
        $this->isRunning = false;
       
        // 等待动画协程结束
        if ($this->handle) {
            // Swoole 4.8+ 版本推荐使用 Scheduler::join($this->handle)
            // 对于较早版本，可以通过轮询 $this->isRunning 来判断，或者此处不强制等待，因为程序即将退出
            // 为了演示，我们可以简单地等待一小会儿让动画协程自然退出其循环
            \co::sleep(0.2); // 给一点时间让动画协程检测到 $this->isRunning 变为 false
        }
    }
}
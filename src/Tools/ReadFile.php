<?php
namespace App\Tools;
class ReadFile extends Tool
{
    public function __construct()
    {
        $this->name = 'read_file';
        $this->desc = 'read a file by the path.结果将以cat -n格式返回,行号从1开始.The path must be absolute path.';
    }

    /**
     * @param string $path 要读取的文件的绝对路径
     * @return string 文件内容，格式为 cat -n 输出
     */
    // 定义参数类型，Manager 会自动识别
    public function execute(string $path): string
    {
         $path = $params['path'] ?? '';

        if (!file_exists($path)) {
            return "错误：文件不存在 -> {$path}";
        }

        $lines = @file($path);
        if ($lines === false) {
            return "错误：无法读取文件 -> {$path}";
        }

        $output = '';
        foreach ($lines as $lineNumber => $line) {
            $output .= ($lineNumber + 1) . "\t" . $line;
        }

        return rtrim($output);
    }
}

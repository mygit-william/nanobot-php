<?php
namespace App\Tools;
class WriteFile extends Tool
{
    public function __construct()
    {
        $this->name = 'write_file';
        $this->desc = 'Write content to file.For partial edits, prefer edit_file instead.The path must be absolute path.';
    }

    /**
     * @param string $path 要写入的文件的绝对路径
     * @param string $content 要写入的内容
     * @return string 执行结果
     */
    // 定义参数类型，Manager 会自动识别
    public function execute(string $path, string $content): string
    {

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            return "错误：无法创建目录 -> {$dir}";
        }

        if (@file_put_contents($path, $content) === false) {
            return "错误：文件写入失败 -> {$path}";
        }

        $preview = substr($content, 0, 100);
        return "文件写入成功";
    }
}

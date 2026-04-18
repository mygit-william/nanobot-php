<?php
namespace App\Tools;
class EditFile extends Tool
{
    public function __construct()
    {
        $this->name = 'edit_file';
        $this->desc = 'Edit file by replacing, inserting or appending content. The path must be absolute path.';
        $this->parameterSchemas = [
            'operation' => [
                'enum' => ['replace', 'insert', 'append', 'prepend'],
            ],
        ];
    }

    /**
     * @param string $path 文件绝对路径
     * @param string $operation 编辑操作类型，枚举值 replace/insert/append/prepend
     * @param string $new_content 新内容
     * @param string $old_content 旧内容，用于 replace 操作
     * @param int $line_number 插入时的目标行号（可选）
     * @return string 执行结果
     */
    public function execute(string $path, string $operation, string $new_content, string $old_content = '', int $line_number = 0):string
    {
        $filePath = $path;
        $newContent = $new_content;
        $oldContent = $old_content;
        $lineNumber = $line_number;

        if (!file_exists($filePath)) {
            return "错误：文件不存在 -> {$filePath}";
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return "错误：无法读取文件内容 -> {$filePath}";
        }

        return $this->performFileEdit($filePath, $content, $operation, $newContent, $oldContent, $lineNumber);
    }

    /**
     * 执行具体的文件编辑操作
     *
     * @param string $filePath 文件路径
     * @param string $content 文件内容
     * @param string $operation 操作类型
     * @param string $newContent 新内容
     * @param string|null $oldContent 旧内容
     * @param int|null $lineNumber 行号
     * @return string 执行结果
     */
    private function performFileEdit(
        string $filePath,
        string $content,
        string $operation,
        string $newContent,
        ?string $oldContent,
        ?int $lineNumber
    ): string {
        switch ($operation) {
            case 'replace':
                return $this->performReplace($filePath, $content, $oldContent, $newContent);
            case 'append':
                return $this->performAppend($filePath, $content, $newContent);
            case 'prepend':
                return $this->performPrepend($filePath, $content, $newContent);
            case 'insert':
                return $this->performInsert($filePath, $content, $newContent, $lineNumber);
            default:
                return "错误：不支持的操作类型 -> {$operation}";
        }
    }

    /**
     * 执行替换操作
     *
     * @param string $filePath 文件路径
     * @param string $content 文件内容
     * @param string $oldContent 旧内容
     * @param string $newContent 新内容
     * @return string 执行结果
     */
    private function performReplace(string $filePath, string $content, string $oldContent, string $newContent): string
    {
        if (empty($oldContent)) {
            return "错误：replace 操作需要提供 old_content 参数";
        }

        if (strpos($content, $oldContent) === false) {
            return "错误：未找到要替换的内容";
        }

        $newFileContent = str_replace($oldContent, $newContent, $content);

        if (@file_put_contents($filePath, $newFileContent) === false) {
            return "错误：文件编辑失败";
        }

        return "文件编辑成功(替换操作)，已替换内容";
    }

    /**
     * 执行追加操作
     *
     * @param string $filePath 文件路径
     * @param string $content 文件内容
     * @param string $newContent 新内容
     * @return string 执行结果
     */
    private function performAppend(string $filePath, string $content, string $newContent): string
    {
        $newFileContent = $content . $newContent;

        if (@file_put_contents($filePath, $newFileContent) === false) {
            return "错误：文件编辑失败";
        }

        return "文件编辑成功(追加操作)，已追加内容";
    }

    /**
     * 执行前置操作
     *
     * @param string $filePath 文件路径
     * @param string $content 文件内容
     * @param string $newContent 新内容
     * @return string 执行结果
     */
    private function performPrepend(string $filePath, string $content, string $newContent): string
    {
        $newFileContent = $newContent . $content;

        if (@file_put_contents($filePath, $newFileContent) === false) {
            return "错误：文件编辑失败";
        }

        return "文件编辑成功(前置操作)，已前置内容";
    }

    /**
     * 执行插入操作
     *
     * @param string $filePath 文件路径
     * @param string $content 文件内容
     * @param string $newContent 新内容
     * @param int|null $lineNumber 行号
     * @return string 执行结果
     */
    private function performInsert(
        string $filePath,
        string $content,
        string $newContent,
        ?int $lineNumber
    ): string {
        if ($lineNumber === null) {
            return "错误：insert 操作需要提供 line_number 参数";
        }

        $lines = explode("\n", $content);
        $lineCount = \count($lines);

        if ($lineNumber < 1 || $lineNumber > $lineCount + 1) {
            return "错误：行号超出范围";
        }

        \array_splice($lines, $lineNumber - 1, 0, $newContent);
        $newFileContent = implode("\n", $lines);

        if (@file_put_contents($filePath, $newFileContent) === false) {
            return "错误：文件编辑失败";
        }

        return "文件编辑成功(插入操作)，已在第 {$lineNumber} 行插入内容";
    }

}


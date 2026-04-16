<?php

namespace App\Utils;

/**
 * JSON工具类
 * 重写原生json函数，使其在出错时抛出异常而不是静默失败
 */
class JsonUtil
{
    /**
     * JSON编码并抛出异常
     * @param mixed $value 要编码的值
     * @param int $options JSON编码选项
     * @param int $depth 深度限制
     * @return string JSON字符串
     * @throws \JsonException 当编码失败时
     */
    public static function encode($value, int $options = JSON_UNESCAPED_UNICODE, int $depth = 512): string
    {
        $json = json_encode($value, $options | JSON_THROW_ON_ERROR, $depth);
        return $json;
    }

    /**
     * JSON解码并抛出异常
     * @param string $json JSON字符串
     * @param bool $associative 是否返回关联数组
     * @param int $depth 解码深度限制
     * @param int $flags 解码选项
     * @return mixed 解码后的值
     * @throws \JsonException 当解码失败时
     */
    public static function decode(string $json, bool $associative = false, int $depth = 512, int $flags = 0)
    {
        $data = json_decode($json, $associative, $depth, JSON_THROW_ON_ERROR | $flags);
        return $data;
    }

    /**
     * 安全的JSON编码（带错误处理）
     * @param mixed $value 要编码的值
     * @param int $options JSON编码选项
     * @param int $depth 深度限制
     * @return array [成功状态, 结果/错误消息]
     */
    public static function encodeSafe($value, int $options = JSON_UNESCAPED_UNICODE, int $depth = 512): array
    {
        $result = self::encode($value, $options, $depth);
        return [true, $result];
    }

    /**
     * 安全的JSON解码（带错误处理）
     * @param string $json JSON字符串
     * @param bool $associative 是否返回关联数组
     * @param int $depth 解码深度限制
     * @param int $flags 解码选项
     * @return array [成功状态, 结果/错误消息]
     */
    public static function decodeSafe(string $json, bool $associative = false, int $depth = 512, int $flags = 0): array
    {
        try {
            $result = self::decode($json, $associative, $depth, $flags);
            return [true, $result];
        } catch (\JsonException $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * 检查JSON字符串是否有效
     * @param string $json JSON字符串
     * @return bool
     */
    public static function isValid(string $json): bool
    {
        json_decode($json, null, 512, JSON_THROW_ON_ERROR);
        return true;
    }

    /**
     * 获取JSON编码错误信息
     * @return string
     */
    public static function getEncodeError(): string
    {
        if (function_exists('json_last_error_msg')) {
            return json_last_error_msg();
        }
        $errors = [
            JSON_ERROR_NONE => 'No error',
            JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
            JSON_ERROR_STATE_MISMATCH => 'State mismatch (invalid or malformed JSON)',
            JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
            JSON_ERROR_SYNTAX => 'Syntax error',
            JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded',
            JSON_ERROR_RECURSION => 'Recursion detected',
            JSON_ERROR_INF_OR_NAN => 'Inf or NaN not allowed',
            JSON_ERROR_UNSUPPORTED_TYPE => 'Unsupported type',
            JSON_ERROR_INVALID_PROPERTY_NAME => 'Invalid property name',
            JSON_ERROR_UTF16 => 'Malformed UTF-16 characters, possibly incorrectly encoded',
        ];
        $code = json_last_error();
        return $errors[$code] ?? 'Unknown error';
    }

    /**
     * 获取JSON解码错误信息
     * @return string
     */
    public static function getDecodeError(): string
    {
        return self::getEncodeError();
    }
}

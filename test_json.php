<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Utils\JsonUtil;

echo "=== JSON工具类测试 ===\n\n";

// 测试1: 正常编码
echo "测试1: 正常JSON编码\n";
try {
    $data = ['name' => '张三', 'age' => 25, 'city' => '北京'];
    $json = JsonUtil::encode($data);
    echo "编码成功: $json\n\n";
} catch (\JsonException $e) {
    echo "编码失败: " . $e->getMessage() . "\n\n";
}

// 测试2: 正常解码
echo "测试2: 正常JSON解码\n";
try {
    $json = '{"name": "李四", "age": 30, "city": "上海"}';
    $data = JsonUtil::decode($json);
    echo "解码成功: " . print_r($data, true) . "\n\n";
} catch (\JsonException $e) {
    echo "解码失败: " . $e->getMessage() . "\n\n";
}

// 测试3: 无效JSON字符串（应该抛出异常）
echo "测试3: 无效JSON字符串解码测试\n";
try {
    $invalidJson = '{"name": "王五", "age": 35, }'; // 注意尾随逗号
    $data = JsonUtil::decode($invalidJson);
    echo "解码成功: " . print_r($data, true) . "\n\n";
} catch (\JsonException $e) {
    echo "✓ 捕获到预期异常: " . $e->getMessage() . "\n\n";
}

// 测试4: 嵌套对象解码（应该抛出异常）
echo "测试4: 嵌套对象解码测试\n";
try {
    $nestedJson = '{"user": {"name": "赵六", "profile": {"age": 28}}';
    $data = JsonUtil::decode($nestedJson);
    echo "解码成功: " . print_r($data, true) . "\n\n";
} catch (\JsonException $e) {
    echo "✓ 捕获到预期异常: " . $e->getMessage() . "\n\n";
}

// 测试5: 空值编码
echo "测试5: 空值编码测试\n";
try {
    $emptyData = null;
    $json = JsonUtil::encode($emptyData);
    echo "编码成功: $json\n\n";
} catch (\JsonException $e) {
    echo "编码失败: " . $e->getMessage() . "\n\n";
}

// 测试6: 大对象深度测试
echo "测试6: 大对象深度测试\n";
try {
    $deepArray = [];
    $current = &$deepArray;
    for ($i = 0; $i < 1000; $i++) {
        $current[] = ['level' => $i, 'data' => []];
        $current = &$current[count($current) - 1]['data'];
    }
    $json = JsonUtil::encode($deepArray);
    echo "✓ 编码成功，长度: " . strlen($json) . "\n\n";
} catch (\JsonException $e) {
    echo "✗ 编码失败: " . $e->getMessage() . "\n\n";
}

// 测试7: 测试Safe方法
echo "测试7: Safe方法测试\n";
$result = JsonUtil::decodeSafe('{"name": "钱七", "age": 32}');
if ($result[0]) {
    echo "✓ Safe解码成功: " . print_r($result[1], true) . "\n\n";
} else {
    echo "✗ Safe解码失败: " . $result[1] . "\n\n";
}

$result = JsonUtil::decodeSafe('{"name": "孙八", "age": 36, }'); // 无效JSON
if (!$result[0]) {
    echo "✓ 捕获到预期错误: " . $result[1] . "\n\n";
}

// 测试8: 测试isValid方法
echo "测试8: isValid方法测试\n";
$validJson = '{"status": "success", "code": 200}';
$invalidJson = '{"status": "error", "code": 400, }';

echo "有效JSON: " . (JsonUtil::isValid($validJson) ? '是' : '否') . "\n";
echo "无效JSON: " . (JsonUtil::isValid($invalidJson) ? '是' : '否') . "\n\n";

// 测试9: 测试错误信息获取
echo "测试9: 错误信息获取\n";
echo "JSON编码错误: " . JsonUtil::getEncodeError() . "\n";
echo "JSON解码错误: " . JsonUtil::getDecodeError() . "\n\n";

// 测试10: 编码选项测试
echo "测试10: 编码选项测试\n";
$data = ['name' => '周九', 'msg' => "Hello\nWorld"];
echo "不转义换行符: " . JsonUtil::encode($data) . "\n";
echo "转义换行符: " . JsonUtil::encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . "\n\n";

echo "=== 测试完成 ===\n";

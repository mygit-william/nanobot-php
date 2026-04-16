<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新闻搜索</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .search-form {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .search-input {
            width: 70%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        .search-button {
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .news-item {
            background: white;
            padding: 20px;
            margin-bottom: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .news-title {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        .news-content {
            color: #666;
            line-height: 1.6;
            margin-bottom: 10px;
        }
        .news-meta {
            color: #999;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>新闻搜索</h1>
        
        <form class="search-form" method="GET" action="">
            <input type="text" name="q" class="search-input" placeholder="输入搜索关键词..." value="<?php echo isset($_GET['q']) ? htmlspecialchars($_GET['q']) : ''; ?>">
            <button type="submit" class="search-button">搜索</button>
        </form>

        <div id="news-results">
            <?php
            // 示例新闻数据 - 替换为你的实际数据源
            $newsData = [
                [
                    'title' => '示例新闻标题 1',
                    'content' => '这里是新闻内容摘要...',
                    'date' => '2024-01-15',
                    'source' => '新闻来源'
                ],
                [
                    'title' => '示例新闻标题 2',
                    'content' => '这里是另一条新闻内容...',
                    'date' => '2024-01-14',
                    'source' => '新闻来源'
                ]
            ];

            // 显示搜索结果
            foreach ($newsData as $news): ?>
                <div class="news-item">
                    <h2 class="news-title"><?php echo htmlspecialchars($news['title']); ?></h2>
                    <p class="news-content"><?php echo htmlspecialchars($news['content']); ?></p>
                    <div class="news-meta">
                        <?php echo htmlspecialchars($news['date']); ?> | <?php echo htmlspecialchars($news['source']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
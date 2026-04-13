# 记忆文件

## 用户偏好

## 技术栈
- PHP

## 个人习惯

## 开发工具
- Docker命令: `docker run -d --name swoole-app --restart always -p 9501:9501 -v $(pwd):/var/www/html phpswoole/swoole` - 用于安装和运行swoole\n
## JSON 处理问题解决方案
我被json_encode搞怕了,转换失败也不报错,返回false,使用json_last_error定位错误:非utf-8-->解决方案:转utf8
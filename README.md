1.1 增加密码验证，默认密码admin123，删除.htpasswd为重置密码

1.0 无密码版


m3u8-downloader主页：https://github.com/llychao/m3u8-downloader

m3u8-downloader下载：https://github.com/llychao/m3u8-downloader/releases

M3U8连接获取，可通过手机浏览器或者pc浏览器猫爪插件。

通过deepseek，采用php代码实现web页面操作，支持自定义线程，输入M3U8连接，支持下载自定义文件名称，支持后台运行，支持记录日志。支持后台结束任务！！！


// 配置文件

定义('APP_ROOT', '/var/www/html/m3u8'); // 默认index.php运行目录

定义('DEFAULT_DOWNLOAD_DIR', '/media/wang.ntfs/downloads'); // 默认下载目录

定义('LOG_DIR', APP_ROOT . '/logs'); // 默认日志目录

定义('BINARY_PATH', APP_ROOT . '/m3u8-downloader'); // 默认m3u8-downloader主程序

定义('DEFAULT_FILENAME', 'movie'); // 默认下载文件命名

定义('ACTIVE_TASKS_FILE', LOG_DIR . '/active_tasks.json'); // 活跃任务存储文件


运行前：

<img width="822" height="720" alt="无标题" src="https://github.com/user-attachments/assets/ff44da88-5866-4be6-9215-87817ce35543" />


运行后：

<img width="822" height="720" alt="无标题" src="https://github.com/user-attachments/assets/d5162932-f834-4a42-9d75-c96eb209d0e1" />


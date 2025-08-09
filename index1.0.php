<?php
// 配置文件
define('APP_ROOT'定义('APP_ROOT', '/var/www/html/m3u8'); // 默认文件名'/var/www/html/m3u8'); // 默认文件名
define('DEFAULT_DOWNLOAD_DIR'定义('DEFAULT_DOWNLOAD_DIR', '/media/wang.ntfs/downloads'); // 默认下载目录'/media/wang.ntfs/downloads'); // 默认下载目录
define('LOG_DIR'定义('LOG_DIR', APP_ROOT . '/logs'); // 默认日志APP_ROOT . '/logs'); // 默认日志
define('BINARY_PATH'定义('BINARY_PATH', APP_ROOT . '/m3u8-downloader'); // 默认m3u8-downloader主程序APP_ROOT . '/m3u8-downloader'); // 默认m3u8-downloader主程序
define('DEFAULT_FILENAME'定义('DEFAULT_FILENAME', 'movie'); // 默认下载文件命名'movie'); // 默认下载文件命名
define('ACTIVE_TASKS_FILE'定义('ACTIVE_TASKS_FILE', LOG_DIR . '/active_tasks.json'); // 活跃任务存储文件LOG_DIR . '/active_tasks.json'); // 活跃任务存储文件

// 开启输出缓冲
ob_start()ob_start();

// 创建必要目录
@mkdir(DEFAULT_DOWNLOAD_DIR, 0777, true);mkdir(DEFAULT_DOWNLOAD_DIR, 0777, true);
@mkdir(LOG_DIR, 0777, true);mkdir(LOG_DIR, 0777, true);

// 错误处理设置
ini_set('display_errors'ini_set('display_errors', 0);0);
ini_set('log_errors'ini_set('log_errors', 1);1);
ini_set('error_log'ini_set('error_log', LOG_DIR . '/php_errors.log');LOG_DIR . '/php_errors.log');

// 初始化活跃任务文件
ifif (!file_exists(ACTIVE_TASKS_FILE)) {(!file_exists(ACTIVE_TASKS_FILE)) {
    file_put_contents(ACTIVE_TASKS_FILE, '[]');file_put_contents(ACTIVE_TASKS_FILE, '[]');
}

// 处理获取活跃任务请求
ifif (isset($_GET['get_active_tasks'])) {(isset($_GET['get_active_tasks'])) {
    尝试 {try {
        $activeTasks = json_decode(file_get_contents(ACTIVE_TASKS_FILE), true) ?: [];$activeTasks = json_decode(file_get_contents(ACTIVE_TASKS_FILE), true) ?: [];
        
        // 过滤掉已完成的任务// 过滤掉已完成的任务
        $filteredTasks = [];$filteredTasks = [];
        foreach ($activeTasks as $task) {foreach ($activeTasks as $task) {
            // 检查PID文件是否存在// 检查PID文件是否存在
            $pidFile = $task['pid_file'] ?? '';$pidFile = $task['pid_file'] ?? '';
            if (!file_exists($pidFile)) {
                continue;
            }
            
            // 检查进程是否仍在运行
            $pid = trim(file_get_contents($pidFile));
            if (is_numeric($pid)) {
                exec("ps -p $pid", $output, $result);
                if (count($output) > 1) {
                    $filteredTasks[] = $task;
                } else {
                    // 进程已结束但任务未清理
                    @unlink($task['lock_file']);
                    @unlink($task['pid_file']);
                }
            }
        }
        
        // 更新活跃任务文件
        file_put_contents(ACTIVE_TASKS_FILE, json_encode($filteredTasks));
        
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode($filteredTasks);
        exit;
    } catch (Exception $e) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

// 处理取消任务请求
if (isset($_POST['cancel_task'])) {
    try {
        $taskId = $_POST['task_id'] ?? '';
        if (empty($taskId)) {
            throw new Exception('缺少任务ID');
        }

        // 加载活跃任务列表
        $activeTasks = json_decode(file_get_contents(ACTIVE_TASKS_FILE), true) ?: [];
        $found = false;
        
        foreach ($activeTasks as $index => $task) {
            if ($task['task_id'] === $taskId) {
                $found = true;
                
                // 获取PID
                if (file_exists($task['pid_file'])) {
                    $pid = trim(file_get_contents($task['pid_file']));
                    if (is_numeric($pid)) {
                        // 终止进程及其子进程
                        exec("pkill -P $pid");
                        exec("kill $pid");
                    }
                }
                
                // 清理文件
                @unlink($task['lock_file']);
                @unlink($task['pid_file']);
                
                // 添加取消标记
                $cancelInfo = [
                    'task_id' => $taskId,
                    'filename' => $task['filename'],
                    'url' => $task['url'],
                    'cancel_time' => date('Y-m-d H:i:s')
                ];
                file_put_contents(LOG_DIR . "/{$taskId}_cancel.log", json_encode($cancelInfo, JSON_PRETTY_PRINT));
                
                // 从活跃任务中移除
                unset($activeTasks[$index]);
                
                // 保存更新后的任务列表
                file_put_contents(ACTIVE_TASKS_FILE, json_encode(array_values($activeTasks)));
                
                break;
            }
        }
        
        if (!$found) {
            throw new Exception('任务不存在或已完成');
        }
        
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => '任务已取消'
        ]);
        exit;
    } catch (Exception $e) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// 处理下载日志请求
if (isset($_GET['download_log'])) {
    $taskId = $_GET['task_id'] ?? '';
    $logFile = LOG_DIR . '/' . $taskId . '.log';
    
    if (!empty($taskId) && file_exists($logFile)) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . basename($logFile) . '"');
        readfile($logFile);
        exit;
    } else {
        http_response_code(404);
        echo '日志文件不存在';
        exit;
    }
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $url = $_POST['url'] ?? '';
        $filename = $_POST['filename'] ?? DEFAULT_FILENAME;
        $threads = (int)($_POST['threads'] ?? 24);

        // 验证输入
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception('无效的URL地址');
        }

        // 修复：允许Unicode字符，只移除危险字符
        $safeFilename = preg_replace('/[<>:"\/\\\|\?\*]/', '', $filename);
        
        // 如果过滤后为空，则使用默认值
        if (empty($safeFilename)) {
            $safeFilename = DEFAULT_FILENAME;
        }
        
        // 创建带时间戳的子目录（基于文件名）
        $timestamp = date('Ymd_His');
        $finalSubdir = $safeFilename . '_' . $timestamp;
        $savePath = DEFAULT_DOWNLOAD_DIR . '/' . $finalSubdir;
        
        if (!is_dir($savePath)) {
            mkdir($savePath, 0777, true);
        }

        // 生成唯一任务ID
        $taskId = uniqid('task_');
        $logFile = LOG_DIR . '/' . $taskId . '.log';
        $lockFile = LOG_DIR . '/' . $taskId . '.lock';
        $pidFile = LOG_DIR . '/' . $taskId . '.pid';

        // 构建命令并记录PID
        $cmd = sprintf(
            'nohup %s -u %s -o %s -n %d -sp %s > %s 2>&1 & echo $! > %s && wait $!',
            escapeshellarg(BINARY_PATH),
            escapeshellarg($url),
            escapeshellarg($safeFilename),
            $threads,
            escapeshellarg($savePath),
            escapeshellarg($logFile),
            escapeshellarg($pidFile)
        );

        // 创建锁文件
        file_put_contents($lockFile, date('Y-m-d H:i:s') . " 任务开始: $url");

        // 在后台执行命令
        shell_exec("($cmd) > /dev/null &");

        // 添加到活跃任务列表
        $activeTasks = json_decode(file_get_contents(ACTIVE_TASKS_FILE), true) ?: [];
        $activeTasks[] = [
            'task_id' => $taskId,
            'url' => $url,
            'filename' => $safeFilename,
            'save_path' => $savePath,
            'start_time' => date('Y-m-d H:i:s'),
            'log_file' => $logFile,
            'lock_file' => $lockFile,
            'pid_file' => $pidFile
        ];
        file_put_contents(ACTIVE_TASKS_FILE, json_encode($activeTasks));

        // 返回响应
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => '下载任务已开始',
            'download_dir' => $savePath,
            'task_id' => $taskId,
            'filename' => $safeFilename,
            'subdir' => $finalSubdir
        ]);
        exit;
    } catch (Exception $e) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M3U8视频下载器</title>
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #34495e;
            --gray-color: #95a5a6;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Segoe UI', 'Microsoft YaHei', sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f5f7fa;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        header {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        
        h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .content {
            padding: 30px;
        }
        
        .section-title {
            font-size: 1.5rem;
            color: var(--secondary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-color);
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark-color);
        }
        
        input[type="url"],
        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        .note {
            font-size: 0.85rem;
            color: var(--gray-color);
            margin-top: 6px;
        }
        
        button {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 14px 25px;
            font-size: 1.1rem;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            width: 100%;
            font-weight: 600;
        }
        
        button:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        button:active {
            transform: translateY(0);
        }
        
        .spinner {
            display: none;
            margin-right: 10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top: 3px solid white;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        #result {
            margin-top: 30px;
            padding: 20px;
            border-radius: 8px;
            background-color: var(--light-color);
            display: none;
        }
        
        .task-list {
            margin-top: 30px;
        }
        
        .task-item {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        
        .task-info {
            flex: 1;
        }
        
        .task-title {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 5px;
        }
        
        .task-detail {
            font-size: 0.9rem;
            color: var(--gray-color);
            margin-bottom: 3px;
        }
        
        .task-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 0.9rem;
            cursor: pointer;
            border: none;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-view {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-view:hover {
            background: #2980b9;
        }
        
        .btn-cancel {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-cancel:hover {
            background: #c0392b;
        }
        
        footer {
            text-align: center;
            padding: 20px;
            color: var(--gray-color);
            font-size: 0.9rem;
            border-top: 1px solid #eee;
        }
        
        .progress-container {
            margin-top: 10px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            height: 10px;
        }
        
        .progress-bar {
            height: 100%;
            background: var(--success-color);
            width: 0%;
            transition: width 0.4s ease;
        }
        
        .status-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-active {
            background-color: var(--success-color);
        }
        
        .status-cancelled {
            background-color: var(--danger-color);
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            .content {
                padding: 20px 15px;
            }
            
            h1 {
                font-size: 2rem;
            }
            
            .task-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .task-actions {
                margin-top: 10px;
                width: 100%;
            }
            
            .btn {
                flex: 1;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>M3U8视频下载器</h1>
            <p class="subtitle">高效下载并合并M3U8视频流</p>
        </header>
        
        <div class="content">
            <!-- 下载表单 -->
            <div class="section-title">新建下载任务</div>
            <form id="downloadForm">
                <div class="form-group">
                    <label for="url">M3U8 URL地址 *</label>
                    <input type="url" id="url" name="url" required 
                        placeholder="https://example.com/stream.m3u8">
                    <p class="note">请输入完整的M3U8文件URL地址</p>
                </div>
                
                <div class="form-group">
                    <label for="filename">输出文件名（不含后缀）</label>
                    <input type="text" id="filename" name="filename" 
                        value="<?= DEFAULT_FILENAME ?>"
                        placeholder="movie"
                        pattern="[^<>:\"\/\\\|\?\*]+"
                        title="文件名不能包含以下字符: &lt; &gt; : \ / | ? *">
                    <p class="note">支持中文、字母、数字、下划线和短横线</p>
                    <p class="note">示例: 我的视频 → 最终生成 /我的视频_时间戳/我的视频.mp4</p>
                </div>
                
                <div class="form-group">
                    <label for="threads">下载线程数 (1-100)</label>
                    <input type="number" id="threads" name="threads" 
                        value="24" min="1" max="100">
                    <p class="note">提高线程数可加速下载，但可能增加服务器负载</p>
                </div>
                
                <button type="submit" id="submitBtn">
                    <div class="spinner" id="submitSpinner"></div>
                    <span id="btnText">开始下载</span>
                </button>
            </form>
            
            <div id="result"></div>
            
            <!-- 活跃任务列表 -->
            <div class="task-list">
                <div class="section-title">当前活跃任务</div>
                <div id="activeTasksContainer">
                    <p>正在加载任务列表...</p>
                </div>
            </div>
        </div>
    </div>

    <footer>
        <p>M3U8下载工具 &copy; <?= date('Y') ?> | 版本 1.8</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('downloadForm');
            const resultDiv = document.getElementById('result');
            const submitBtn = document.getElementById('submitBtn');
            const submitSpinner = document.getElementById('submitSpinner');
            const btnText = document.getElementById('btnText');
            const activeTasksContainer = document.getElementById('activeTasksContainer');
            
            // 加载活跃任务
            function loadActiveTasks() {
                fetch('?get_active_tasks=1')
                    .then(response => response.json())
                    .then(tasks => {
                        if (tasks.length === 0) {
                            activeTasksContainer.innerHTML = '<p>当前没有活跃任务</p>';
                            return;
                        }
                        
                        let html = '';
                        tasks.forEach(task => {
                            // 创建文件下载链接
                            const downloadPath = task.save_path.replace('<?= DEFAULT_DOWNLOAD_DIR ?>', '');
                            const downloadLink = `files.php?path=${encodeURIComponent(task.save_path)}`;
                            
                            html += `
                                <div class="task-item" data-task-id="${task.task_id}">
                                    <div class="task-info">
                                        <div class="task-title">
                                            <span class="status-dot status-active"></span>
                                            ${task.filename}
                                        </div>
                                        <div class="task-detail">URL: ${task.url}</div>
                                        <div class="task-detail">开始时间: ${task.start_time}</div>
                                        <div class="task-detail">保存位置: ${downloadPath}</div>
                                        <div class="progress-container">
                                            <div class="progress-bar" id="progress-${task.task_id}"></div>
                                        </div>
                                    </div>
                                    <div class="task-actions">
                                        <button class="btn btn-view" onclick="window.downloadLog('${task.task_id}')">下载日志</button>
                                        <button class="btn btn-cancel" onclick="window.cancelTask('${task.task_id}')">取消任务</button>
                                    </div>
                                </div>
                            `;
                        });
                        activeTasksContainer.innerHTML = html;
                    })
                    .catch(error => {
                        console.error('加载任务失败:', error);
                        activeTasksContainer.innerHTML = '<p>加载任务失败，请刷新页面重试</p>';
                    });
            }
            
            // 初始加载活跃任务
            loadActiveTasks();
            setInterval(loadActiveTasks, 5000);
            
            // 表单提交处理
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // 显示加载状态
                submitSpinner.style.display = 'inline-block';
                btnText.textContent = '处理中...';
                submitBtn.disabled = true;
                
                const formData = new FormData(form);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        resultDiv.innerHTML = `
                            <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px;">
                                <h3>✓ 任务已启动</h3>
                                <p><strong>文件名：</strong> ${data.filename}</p>
                                <p><strong>保存位置：</strong> ${data.download_dir}</p>
                                <p><strong>任务ID：</strong> ${data.task_id}</p>
                                <p>任务已在后台开始执行，您可以继续添加新任务</p>
                            </div>
                        `;
                        form.reset();
                        loadActiveTasks();
                    } else {
                        resultDiv.innerHTML = `
                            <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px;">
                                <h3>✗ 错误发生</h3>
                                <p>${data.message || '未知错误'}</p>
                            </div>
                        `;
                    }
                    resultDiv.style.display = 'block';
                })
                .catch(error => {
                    resultDiv.innerHTML = `
                        <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px;">
                            <h3>✗ 网络错误</h3>
                            <p>无法连接到服务器: ${error.message}</p>
                        </div>
                    `;
                    resultDiv.style.display = 'block';
                })
                .finally(() => {
                    submitSpinner.style.display = 'none';
                    btnText.textContent = '开始下载';
                    submitBtn.disabled = false;
                    
                    // 5秒后隐藏结果
                    setTimeout(() => {
                        resultDiv.style.display = 'none';
                    }, 5000);
                });
            });
            
            // 下载日志函数
            window.downloadLog = function(taskId) {
                window.location.href = `?download_log=1&task_id=${taskId}`;
            };
            
            // 取消任务函数
            window.cancelTask = function(taskId) {
                if (!confirm(`确定要取消任务 "${taskId}" 吗？此操作不可撤销！`)) {
                    return;
                }
                
                const formData = new FormData();
                formData.append('cancel_task', '1');
                formData.append('task_id', taskId);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(`任务 "${taskId}" 已取消`);
                        loadActiveTasks();
                    } else {
                        alert(`取消任务失败: ${data.message}`);
                    }
                })
                .catch(error => {
                    alert(`请求失败: ${error.message}`);
                });
            };
        });
    </script>
</body>
</html>

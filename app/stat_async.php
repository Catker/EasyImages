<?php
/**
 * 异步统计 API
 * 
 * 提供统计数据的异步获取、刷新和进度查询功能
 * 适用于大规模文件目录（17GB+ NFS）的统计场景
 */

require_once __DIR__ . '/function.php';

// 仅允许管理员访问
if (!is_who_login('admin')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => '未授权访问']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// 进度文件路径
$progressFile = APP_ROOT . '/admin/logs/counts/stat_progress.json';

// 获取请求的操作类型
$action = isset($_GET['action']) ? $_GET['action'] : 'status';

switch ($action) {
    case 'status':
        // 获取当前缓存的统计数据
        handleStatus();
        break;
    
    case 'refresh':
        // 启动后台统计任务
        handleRefresh();
        break;
    
    case 'progress':
        // 获取统计进度
        handleProgress();
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => '未知操作']);
        exit;
}

/**
 * 获取当前缓存的统计数据
 */
function handleStatus()
{
    global $config;
    
    // 读取缓存的统计数据
    $totalJsonMD5 = strval(md5_file(APP_ROOT . '/config/config.php'));
    $totalJsonName = APP_ROOT . "/admin/logs/counts/total-files-{$totalJsonMD5}.php";
    $chartJsonName = APP_ROOT . "/admin/logs/counts/chart-{$totalJsonMD5}.php";
    
    $result = [
        'success' => true,
        'cached' => false,
        'data' => null,
        'chart' => null
    ];
    
    // 读取总体统计数据
    if (file_exists($totalJsonName)) {
        $totalData = json_decode(file_get_contents($totalJsonName), true);
        if ($totalData) {
            $result['cached'] = true;
            $result['data'] = [
                'total_time' => $totalData['total_time'] ?? '未知',
                'filenum' => $totalData['filenum'] ?? 0,
                'dirnum' => $totalData['dirnum'] ?? 0,
                'usage_space' => $totalData['usage_space'] ?? '0 B',
                'todayUpload' => $totalData['todayUpload'] ?? 0,
                'yestUpload' => $totalData['yestUpload'] ?? 0
            ];
        }
    }
    
    // 读取图表数据
    if (file_exists($chartJsonName)) {
        $chartData = json_decode(file_get_contents($chartJsonName), true);
        if ($chartData) {
            $dates = [];
            $numbers = [];
            $disks = [];
            
            if (isset($chartData['chart_data'])) {
                foreach (array_reverse($chartData['chart_data'], true) as $item) {
                    foreach ($item as $date => $count) {
                        $dates[] = str_replace(date('Y/'), '', $date);
                        $numbers[] = (int)$count;
                    }
                }
            }
            
            if (isset($chartData['chart_disk'])) {
                foreach (array_reverse($chartData['chart_disk'], true) as $item) {
                    foreach ($item as $size) {
                        $disks[] = round($size / 1024 / 1024, 2);
                    }
                }
            }
            
            $result['chart'] = [
                'total_time' => $chartData['total_time'] ?? '未知',
                'dates' => $dates,
                'numbers' => $numbers,
                'disks' => $disks
            ];
        }
    }
    
    // 获取磁盘信息（这些很快，可以实时获取）
    $result['disk'] = [
        'total' => getDistUsed(disk_total_space('.')),
        'used' => getDistUsed(disk_total_space('.') - disk_free_space('.')),
        'free' => getDistUsed(disk_free_space('.')),
        'percent' => round((disk_total_space('.') - disk_free_space('.')) / disk_total_space('.') * 100, 2)
    ];
    
    // 获取缓存和可疑图片数量（较快）
    $result['quick'] = [
        'cache' => getFileNumber(APP_ROOT . $config['path'] . 'cache/'),
        'suspic' => getFileNumber(APP_ROOT . $config['path'] . 'suspic/')
    ];
    
    echo json_encode($result);
}

/**
 * 启动后台统计任务
 */
function handleRefresh()
{
    global $config, $progressFile;
    
    // 确保目录存在(清除缓存后可能被删除)
    $countsDir = APP_ROOT . '/admin/logs/counts/';
    if (!is_dir($countsDir)) {
        @mkdir($countsDir, 0755, true);
    }
    
    // 检查是否已有统计任务在运行
    if (file_exists($progressFile)) {
        $progress = json_decode(file_get_contents($progressFile), true);
        if ($progress && isset($progress['status']) && $progress['status'] === 'running') {
            // 检查是否超时（5分钟）
            if (time() - ($progress['start_time'] ?? 0) < 300) {
                echo json_encode([
                    'success' => false,
                    'error' => '统计任务正在进行中',
                    'progress' => $progress
                ]);
                return;
            }
        }
    }
    
    // 立即返回响应给客户端
    echo json_encode([
        'success' => true,
        'message' => '统计任务已启动'
    ]);
    
    // 关闭 HTTP 连接,后续代码在后台执行
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    // ============================================================
    // 以下代码在后台执行(如果 fastcgi_finish_request 可用)
    // 或在 HTTP 连接中同步执行(降级模式)
    // ============================================================
    
    // 设置无限执行时间
    set_time_limit(0);
    ignore_user_abort(true);
    
    // 初始化进度
    updateProgress('running', 0, '正在准备统计...');
    
    try {
        // 记录开始时间,用于超时检测
        $startTime = time();
        $maxExecutionTime = 180; // 最大执行时间 3 分钟
        
        // 阶段1：统计文件数量 (0-40%)
        updateProgress('running', 5, '正在统计文件数量...');
        
        // 引入统计函数
        global $dirn, $filen;
        $dirn = 0;
        $filen = 0;
        
        $total_file_path = APP_ROOT . $config['path'];
        
        // 检查 shell_exec 是否可用
        $canUseShell = function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))));
        
        // 使用系统命令统计（NFS 友好,带超时保护）
        $realPath = realpath($total_file_path);
        if ($realPath !== false && is_dir($realPath)) {
            
            if ($canUseShell) {
                // 方案A: 使用系统命令(快速,适合大目录)
                updateProgress('running', 10, '正在统计文件数（使用系统命令）...');
                
                $escapedPath = escapeshellarg($realPath);
                
                // 检查是否有 timeout 命令 (Linux) 或 gtimeout (macOS with coreutils)
                $timeoutCmd = @shell_exec('which timeout 2>/dev/null') ?: @shell_exec('which gtimeout 2>/dev/null');
                $hasTimeout = !empty(trim($timeoutCmd));
                $timeoutBin = $hasTimeout ? trim($timeoutCmd) : '';
                
                // 统计文件数
                if ($hasTimeout) {
                    // 使用 timeout 限制 find 命令执行时间为 60 秒
                    $fileResult = @shell_exec("{$timeoutBin} 60 find {$escapedPath} -type f 2>/dev/null | wc -l");
                } else {
                    // 没有 timeout 命令,使用后台执行 + 轮询方式
                    $tmpFile = sys_get_temp_dir() . '/stat_file_count_' . getmypid() . '.txt';
                    $cmd = "nohup sh -c 'find {$escapedPath} -type f 2>/dev/null | wc -l > {$tmpFile}' > /dev/null 2>&1 &";
                    @shell_exec($cmd);
                    
                    // 轮询等待结果,最多等待 60 秒
                    $waited = 0;
                    while ($waited < 60 && !file_exists($tmpFile)) {
                        sleep(2);
                        $waited += 2;
                        if ($waited % 10 == 0) {
                            updateProgress('running', 10 + ($waited / 6), "正在统计文件数... ({$waited}s)");
                        }
                    }
                    
                    if (file_exists($tmpFile)) {
                        $fileResult = file_get_contents($tmpFile);
                        @unlink($tmpFile);
                    } else {
                        $fileResult = null;
                    }
                }
                
                if ($fileResult !== null && trim($fileResult) !== '') {
                    $filen = (int)trim($fileResult);
                    updateProgress('running', 20, "已统计 {$filen} 个文件");
                } else {
                    updateProgress('running', 20, '文件统计超时,将跳过此项...');
                    $filen = 0;
                }
                
                // 统计目录数
                updateProgress('running', 25, '正在统计目录数...');
                
                if ($hasTimeout) {
                    $dirResult = @shell_exec("{$timeoutBin} 30 find {$escapedPath} -type d 2>/dev/null | wc -l");
                } else {
                    // 使用后台执行方式
                    $tmpFile = sys_get_temp_dir() . '/stat_dir_count_' . getmypid() . '.txt';
                    $cmd = "nohup sh -c 'find {$escapedPath} -type d 2>/dev/null | wc -l > {$tmpFile}' > /dev/null 2>&1 &";
                    @shell_exec($cmd);
                    
                    $waited = 0;
                    while ($waited < 30 && !file_exists($tmpFile)) {
                        sleep(2);
                        $waited += 2;
                        if ($waited % 10 == 0) {
                            updateProgress('running', 25 + ($waited / 3), "正在统计目录数... ({$waited}s)");
                        }
                    }
                    
                    if (file_exists($tmpFile)) {
                        $dirResult = file_get_contents($tmpFile);
                        @unlink($tmpFile);
                    } else {
                        $dirResult = null;
                    }
                }
                
                if ($dirResult !== null && trim($dirResult) !== '') {
                    $dirn = max(0, (int)trim($dirResult) - 1);
                    updateProgress('running', 35, "已统计 {$dirn} 个目录");
                } else {
                    updateProgress('running', 35, '目录统计超时,将跳过此项...');
                    $dirn = 0;
                }
                
            } else {
                // 方案B: 使用纯 PHP 方式(shell_exec 被禁用时的降级方案)
                updateProgress('running', 10, '正在统计文件数（使用 PHP 递归）...');
                
                try {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($realPath, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST,
                        RecursiveIteratorIterator::CATCH_GET_CHILD
                    );
                    
                    $fileCount = 0;
                    $dirCount = 0;
                    $lastUpdate = time();
                    
                    foreach ($iterator as $item) {
                        // 超时检查
                        if ((time() - $startTime) > $maxExecutionTime) {
                            updateProgress('running', 35, '统计超时,使用已统计数据...');
                            break;
                        }
                        
                        if ($item->isFile()) {
                            $fileCount++;
                            // 每1000个文件更新一次进度
                            if ($fileCount % 1000 == 0 && (time() - $lastUpdate) >= 2) {
                                $progress = min(30, 10 + ($fileCount / 100));
                                updateProgress('running', (int)$progress, "已统计 {$fileCount} 个文件...");
                                $lastUpdate = time();
                            }
                        } elseif ($item->isDir()) {
                            $dirCount++;
                        }
                    }
                    
                    $filen = $fileCount;
                    $dirn = $dirCount;
                    
                    updateProgress('running', 35, "统计完成: {$filen} 个文件, {$dirn} 个目录");
                    
                } catch (Exception $e) {
                    updateProgress('running', 35, 'PHP 统计遇到错误,将跳过此项...');
                    $filen = 0;
                    $dirn = 0;
                }
            }
        } // 关闭 if ($realPath !== false && is_dir($realPath))
        
        updateProgress('running', 40, '文件统计完成,正在计算空间占用...');
        
        // 阶段2：计算空间占用 (40-60%)
        $usage_space = getDistUsed(getDirectorySize(APP_ROOT . $config['path']));
        
        updateProgress('running', 60, '正在统计今日上传量...');
        
        // 阶段3：统计今日/昨日上传 (60-70%)
        $todayUpload = getFileNumber(APP_ROOT . config_path());
        $yestUpload = getFileNumber(APP_ROOT . $config['path'] . date("Y/m/d/", strtotime("-1 day")));
        
        updateProgress('running', 70, '正在保存基础统计数据...');
        
        // 保存总体统计数据
        $totalJsonMD5 = strval(md5_file(APP_ROOT . '/config/config.php'));
        $totalJsonName = APP_ROOT . "/admin/logs/counts/total-files-{$totalJsonMD5}.php";
        
        $totalJsonInfo = [
            'filename'    => $totalJsonMD5,
            'date'        => date('YmdH'),
            'total_time'  => date('Y-m-d H:i:s'),
            'dirnum'      => $dirn,
            'filenum'     => $filen,
            'usage_space' => $usage_space,
            'todayUpload' => $todayUpload,
            'yestUpload'  => $yestUpload
        ];
        
        if (!is_dir(APP_ROOT . '/admin/logs/counts/')) {
            mkdir(APP_ROOT . '/admin/logs/counts/', 0755, true);
        }
        file_put_contents($totalJsonName, json_encode($totalJsonInfo));
        
        updateProgress('running', 75, '正在统计近30日数据...');
        
        // 阶段4：统计图表数据 (75-95%)
        $count_day = [];
        $now = time();
        for ($i = 0; $i < 30; $i++) {
            $count_day[] = date('Y/m/d/', strtotime('-' . $i . ' day', $now));
        }
        
        $chartJsonName = APP_ROOT . "/admin/logs/counts/chart-{$totalJsonMD5}.php";
        $count_contents = [
            'filename' => $totalJsonMD5,
            'total_time' => date('Y-m-d H:i:s'),
            'date' => date('YmdH'),
            'chart_data' => [],
            'chart_disk' => []
        ];
        
        $total_contents = APP_ROOT . $config['path'];
        $dayCount = count($count_day);
        
        for ($i = 0; $i < $dayCount; $i++) {
            $progress = 75 + ($i / $dayCount) * 20;
            $dayPath = $total_contents . $count_day[$i];
            
            if ($i % 5 === 0) {
                updateProgress('running', (int)$progress, "正在统计 {$count_day[$i]} 数据...");
            }
            
            $count_contents['chart_data'][] = [$count_day[$i] => getFileNumber($dayPath)];
            $count_contents['chart_disk'][] = [$count_day[$i] => getDirectorySize($dayPath)];
        }
        
        file_put_contents($chartJsonName, json_encode($count_contents));
        
        updateProgress('completed', 100, '统计完成！');
        
        // 返回最新数据
        echo json_encode([
            'success' => true,
            'message' => '统计完成',
            'data' => $totalJsonInfo
        ]);
        
    } catch (Exception $e) {
        updateProgress('error', 0, '统计失败：' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * 获取统计进度
 */
function handleProgress()
{
    global $progressFile;
    
    if (file_exists($progressFile)) {
        $progress = json_decode(file_get_contents($progressFile), true);
        echo json_encode([
            'success' => true,
            'progress' => $progress
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'progress' => [
                'status' => 'idle',
                'percent' => 0,
                'message' => '无统计任务'
            ]
        ]);
    }
}

/**
 * 更新进度
 */
function updateProgress($status, $percent, $message)
{
    global $progressFile;
    
    $progress = [
        'status' => $status,
        'percent' => $percent,
        'message' => $message,
        'start_time' => $status === 'running' && $percent < 10 ? time() : null,
        'update_time' => time()
    ];
    
    // 保留开始时间
    if (file_exists($progressFile)) {
        $old = json_decode(file_get_contents($progressFile), true);
        if ($old && isset($old['start_time']) && $old['start_time']) {
            $progress['start_time'] = $old['start_time'];
        }
    }
    
    if (!is_dir(dirname($progressFile))) {
        mkdir(dirname($progressFile), 0755, true);
    }
    
    file_put_contents($progressFile, json_encode($progress));
}

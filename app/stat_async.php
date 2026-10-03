<?php
/**
 * 异步统计 API
 * 
 * 提供统计数据的异步获取、刷新和进度查询功能
 * 适用于大规模文件目录（17GB+ NFS）的统计场景
 */

require_once __DIR__ . '/chart.php'; // 含 function.php 与 total_files.php

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
    $total = disk_total_space('.');
    $free = disk_free_space('.');
    $result['disk'] = [
        'total' => getDistUsed($total),
        'used' => getDistUsed($total - $free),
        'free' => getDistUsed($free),
        'percent' => round(($total - $free) / $total * 100, 2)
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
    global $progressFile;
    
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

    set_time_limit(0);
    ignore_user_abort(true);

    updateProgress('running', 5, '正在统计文件数量与空间占用...', time());
    creat_json();

    updateProgress('running', 70, '正在统计近30日数据...');
    write_chart_total();

    updateProgress('completed', 100, '统计完成！');
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
function updateProgress($status, $percent, $message, $startTime = null)
{
    global $progressFile;

    // 未指定开始时间时沿用本次任务的开始时间
    if ($startTime === null && file_exists($progressFile)) {
        $old = json_decode(file_get_contents($progressFile), true);
        $startTime = $old['start_time'] ?? null;
    }

    file_put_contents($progressFile, json_encode([
        'status' => $status,
        'percent' => $percent,
        'message' => $message,
        'start_time' => $startTime,
        'update_time' => time()
    ]));
}

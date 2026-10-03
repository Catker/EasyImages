<?php

/** 禁止直接访问 */
defined('APP_ROOT') ?: exit;

/** 统计文件 */

require_once __DIR__ . '/../config/config.php';

$totalJsonMD5 =  strval(md5_file(APP_ROOT . '/config/config.php')); // 以config.php文件的md5命名
$totalJsonName = APP_ROOT  . "/admin/logs/counts/total-files-$totalJsonMD5.php";       // 文件绝对目录

function creat_json() // 创建json文件
{
    global $totalJsonName;
    global $config;
    global $totalJsonMD5;

    $stats = dir_stats(APP_ROOT . $config['path']); // 一次遍历得到文件数/目录数/空间占用

    $totalJsonInfo = [
        'filename'    => $totalJsonMD5,                      // 统计文件名称
        'date'        => date('YmdH'),                       // 识别日期格式
        'total_time'  => date('Y-m-d H:i:s'),                // 统计时间
        'dirnum'      => $stats['dirs'],                     // 文件夹数量
        'filenum'     => $stats['files'],                    // 文件数量
        'usage_space' => getDistUsed($stats['bytes']),       // 占用空间
        'todayUpload' => getFileNumber(APP_ROOT . config_path()), // 今日上传数量
        'yestUpload'  => getFileNumber(APP_ROOT . $config['path'] . date("Y/m/d/", strtotime("-1 day"))) // 昨日上传数量
    ];
    if (!is_dir(APP_ROOT . '/admin/logs/counts/')) {
        mkdir(APP_ROOT . '/admin/logs/counts/', 0755, true);
    }
    file_put_contents($totalJsonName, json_encode($totalJsonInfo));
    return $totalJsonInfo;
}

function read_total_json($total) // 读取json文件
{
    global $totalJsonFile;
    global $totalJsonName;
    global $config;
    $cache_freq = $config['cache_freq'];

    if (file_exists($totalJsonName)) {
        $totalJsonFile = file_get_contents($totalJsonName);
        $totalJsonFile = json_decode($totalJsonFile, true);
    } else {
        creat_json();
        $totalJsonFile = file_get_contents($totalJsonName);
        $totalJsonFile = json_decode($totalJsonFile, true);
    }

    if ((date('YmdH') - $totalJsonFile['date']) > $cache_freq) {
        creat_json();
        $totalJsonFile = file_get_contents($totalJsonName);
        $totalJsonFile = json_decode($totalJsonFile, true);
    }

    return $totalJsonFile[$total];
}

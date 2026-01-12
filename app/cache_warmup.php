<?php
/**
 * 缓存预热脚本
 * 在后台定期更新缓存,避免用户请求时触发慢速 NFS 扫描
 * 
 * 功能:
 * - 自动读取 config['listDate'] 配置
 * - 预热最近 N 天的日期目录(与广场页面智能显示天数保持一致)
 * - 根据 plaza_cache_type 配置使用 Redis 或文件缓存
 * 
 * 使用方法:
 * php app/cache_warmup.php
 * 
 * Crontab 配置(建议每15分钟执行一次,在缓存过期前刷新):
 * 每15分钟: php /path/to/app/cache_warmup.php >> /var/log/redis_warmup.log 2>&1
 * 
 * 安全说明：此脚本只能通过命令行执行，禁止 HTTP 访问
 */

// 安全检查：只允许 CLI 执行，禁止 HTTP 访问
if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden\n\n此脚本只能通过命令行执行。\nThis script can only be run from the command line.\n\n使用方法: php app/cache_warmup.php";
    exit(1);
}

// 强制输出到标准输出
@ini_set('implicit_flush', 1);
@ob_implicit_flush(1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/function.php';

// 获取缓存类型配置: 0=关闭, 1=文件缓存, 2=Redis缓存
$cacheMode = isset($config['plaza_cache_type']) ? (int)$config['plaza_cache_type'] : 2;

if ($cacheMode === 0) {
    echo "[INFO] 广场缓存已关闭 (plaza_cache_type=0)\n";
    echo "[INFO] 如需启用缓存,请在设置中修改缓存模式\n";
    flush();
    exit(0);
}

try {
    if ($cacheMode === 1) {
        // 强制使用文件缓存
        require_once __DIR__ . '/file_cache.php';
        $cache = new FileCache();
        $cacheType = '文件缓存';
        echo "[INFO] 使用文件缓存 (plaza_cache_type=1)\n";
    } else {
        // Redis 缓存（失败降级到文件缓存）
        try {
            require_once __DIR__ . '/redis_cache.php';
            $cache = new RedisCache(
                $config['redis_host'] ?? '127.0.0.1',
                $config['redis_port'] ?? 6379,
                $config['redis_password'] ?? null
            );
            $cacheType = 'Redis';
            echo "[INFO] Redis 连接成功\n";
        } catch (Exception $e) {
            echo "[WARN] Redis 连接失败: " . $e->getMessage() . "\n";
            require_once __DIR__ . '/file_cache.php';
            $cache = new FileCache();
            $cacheType = '文件缓存';
            echo "[INFO] 已降级到文件缓存\n";
        }
    }
    flush();
} catch (Exception $e) {
    echo "[ERROR] 无法初始化缓存系统: " . $e->getMessage() . "\n";
    flush();
    exit(1);
}

echo "========================================\n";
echo "EasyImage 缓存预热脚本\n";
echo "缓存类型: {$cacheType}\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";
flush();

// 从配置读取智能显示天数,与广场页面保持一致
$days = isset($config['listDate']) ? (int)$config['listDate'] : 10;
$paths = [];

echo "智能显示天数配置: {$days} 天\n";
echo "预热范围: 今天到前 " . ($days - 1) . " 天\n\n";
flush();

for ($i = 0; $i < $days; $i++) {
    $date = date('Y/m/d/', strtotime("-$i day"));
    
    // 使用与 get_file_by_glob() 完全相同的路径解析逻辑
    // 构造查询字符串(与广场页面中的调用保持一致)
    $queryString = APP_ROOT . $config['path'] . $date . '*.*';
    
    // 使用 pathinfo 解析,与 get_file_by_glob 中的逻辑完全一致
    // get_file_by_glob 中: $pathInfo = pathinfo($dir_fileName_suffix); $dir = $pathInfo['dirname'];
    $pathInfo = pathinfo($queryString);
    $path = $pathInfo['dirname'];  // 这就是缓存键中使用的路径
    
    if (is_dir($path)) {
        $paths[] = $path;
        echo "  ✓ 发现目录: {$date}\n";
    } else {
        echo "  - 跳过不存在: {$date}\n";
    }
}


if (empty($paths)) {
    echo "\n[WARN] 没有找到需要预热的目录\n";
    flush();
    exit(0);
}

echo "\n实际预热目录数量: " . count($paths) . "\n\n";
flush();

// 逐个预热并显示进度
$startTime = microtime(true);
$totalFiles = 0;

foreach ($paths as $index => $path) {
    $date = basename($path);
    $current = $index + 1;
    $total = count($paths);
    echo "正在预热 [{$current}/{$total}]: {$date} ... ";
    flush();
    
    $itemStart = microtime(true);
    
    // 使用 forceRefresh=true 强制覆盖缓存并重置 TTL
    // 原子操作：不需要先清除，避免竞争条件导致缓存短暂不可用
    $files = $cache->getFileList($path, '*.*', true);
    
    // 预热文件数量（同样强制刷新）
    $count = $cache->getFileCount($path, false, true);
    
    $itemTime = round((microtime(true) - $itemStart) * 1000, 2);
    
    echo "✓ {$count} 个文件 ({$itemTime}ms)\n";
    flush();
    
    $totalFiles += $count;
}

$endTime = microtime(true);

echo "\n========================================\n";
echo "预热完成\n";
echo "总文件数: {$totalFiles}\n";
echo "耗时: " . round($endTime - $startTime, 2) . " 秒\n";
flush();

// 显示缓存统计(仅 Redis)
if ($cacheType === 'Redis') {
    try {
        $stats = $cache->getStats();
        echo "\nRedis 统计:\n";
        echo "- 缓存键数量: {$stats['total_keys']}\n";
        echo "- 内存使用: {$stats['memory_usage']}\n";
        
        if ($stats['hits'] + $stats['misses'] > 0) {
            $hitRate = round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 2);
            echo "- 命中率: {$hitRate}%\n";
        }
    } catch (Exception $e) {
        // 忽略统计错误
    }
}

echo "========================================\n";
echo "[SUCCESS] 缓存预热完成\n";
flush();

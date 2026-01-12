<?php
/**
 * 缓存状态诊断工具
 * 用于检查缓存是否正常工作
 * 
 * 安全说明：此页面需要管理员登录才能访问
 */

require_once __DIR__ . '/header.php';

// 安全检查：只允许管理员访问
if (!is_who_login('admin')) {
    header('HTTP/1.1 403 Forbidden');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>访问拒绝</title></head>';
    echo '<body style="text-align:center;padding:50px;font-family:Arial,sans-serif;">';
    echo '<h1 style="color:#dc3545;">403 - 访问被拒绝</h1>';
    echo '<p>此页面需要管理员权限才能访问。</p>';
    echo '<a href="../admin/index.php" style="color:#007bff;">返回登录</a>';
    echo '</body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>\n<html>\n<head>\n    <meta charset='utf-8'>\n    <title>Redis 缓存状态诊断</title>\n    <style>\n        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }\n        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }\n        h1 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }\n        h2 { color: #555; margin-top: 30px; }\n        .status { padding: 10px; margin: 10px 0; border-radius: 4px; }\n        .success { background: #d4edda; border-left: 4px solid #28a745; }\n        .error { background: #f8d7da; border-left: 4px solid #dc3545; }\n        .warning { background: #fff3cd; border-left: 4px solid #ffc107; }\n        .info { background: #d1ecf1; border-left: 4px solid #17a2b8; }\n        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }\n        code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; }\n        .btn { display: inline-block; padding: 10px 20px; margin: 10px 5px 10px 0; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px; }\n        .btn:hover { background: #45a049; }\n    </style>\n</head>\n<body>\n<div class='container'>\n<h1>🔍 Redis 缓存状态诊断</h1>\n";

// 1. 检查配置文件
echo "<h2>1️⃣ 配置文件检查</h2>\n";
$hasRedisConfig = isset($config['redis_host']) && isset($config['redis_port']);

if ($hasRedisConfig) {
    echo "<div class='status success'>✅ 配置文件中存在 Redis 配置</div>\n";
    echo "<pre>";
    echo "Redis Host: " . htmlspecialchars($config['redis_host']) . "\n";
    echo "Redis Port: " . htmlspecialchars($config['redis_port']) . "\n";
    echo "Redis Password: " . (isset($config['redis_password']) && $config['redis_password'] ? "已设置" : "未设置") . "\n";
    echo "</pre>";
} else {
    echo "<div class='status error'>❌ 配置文件中缺少 Redis 配置</div>\n";
    echo "<div class='status warning'>⚠️ 需要在 <code>config/config.php</code> 中添加以下配置:</div>\n";
    echo "<pre>'redis_host' => '127.0.0.1',\n'redis_port' => 6379,\n'redis_password' => null,  // 如果设置了密码,在这里填写</pre>";
}

// 2. 检查 Redis 扩展
echo "<h2>2️⃣ PHP Redis 扩展检查</h2>\n";
$hasRedisExtension = extension_loaded('redis');

if ($hasRedisExtension) {
    echo "<div class='status success'>✅ PHP Redis 扩展已安装</div>\n";
    $redisVersion = phpversion('redis');
    echo "<pre>Redis 扩展版本: " . htmlspecialchars($redisVersion) . "</pre>";
} else {
    echo "<div class='status error'>❌ PHP Redis 扩展未安装</div>\n";
    echo "<div class='status warning'>⚠️ 安装方法:</div>\n";
    echo "<pre># Ubuntu/Debian\nsudo apt-get install php-redis\nsudo systemctl restart php-fpm  # 或 apache2/nginx\n\n# CentOS/RHEL\nsudo yum install php-redis\nsudo systemctl restart php-fpm</pre>";
}

// 3. 检查 Redis 服务器连接
echo "<h2>3️⃣ Redis 服务器连接测试</h2>\n";

if ($hasRedisExtension && $hasRedisConfig) {
    try {
        $redis = new Redis();
        $connected = @$redis->connect(
            $config['redis_host'],
            $config['redis_port'],
            2  // 2秒超时
        );
        
        if ($connected) {
            // 如果有密码,尝试认证
            if (isset($config['redis_password']) && $config['redis_password']) {
                $auth = @$redis->auth($config['redis_password']);
                if (!$auth) {
                    throw new Exception("Redis 密码认证失败");
                }
            }
            
            // 测试 ping
            $pong = $redis->ping();
            
            echo "<div class='status success'>✅ Redis 服务器连接成功</div>\n";
            echo "<pre>PING 响应: " . htmlspecialchars($pong) . "</pre>";
            
            // 获取 Redis 信息
            $info = $redis->info();
            echo "<h3>Redis 服务器信息:</h3>\n";
            echo "<pre>";
            echo "Redis 版本: " . htmlspecialchars($info['redis_version'] ?? 'N/A') . "\n";
            echo "运行模式: " . htmlspecialchars($info['redis_mode'] ?? 'N/A') . "\n";
            echo "已用内存: " . htmlspecialchars($info['used_memory_human'] ?? 'N/A') . "\n";
            echo "连接的客户端: " . htmlspecialchars($info['connected_clients'] ?? 'N/A') . "\n";
            echo "</pre>";
            
            // 检查缓存键
            $keys = $redis->keys('easyimage:files:*');
            echo "<h3>缓存状态:</h3>\n";
            if (count($keys) > 0) {
                echo "<div class='status success'>✅ 发现 " . count($keys) . " 个缓存键</div>\n";
                echo "<pre>";
                foreach (array_slice($keys, 0, 10) as $key) {
                    $ttl = $redis->ttl($key);
                    echo htmlspecialchars($key) . " (TTL: " . $ttl . "s)\n";
                }
                if (count($keys) > 10) {
                    echo "... 还有 " . (count($keys) - 10) . " 个键\n";
                }
                echo "</pre>";
            } else {
                echo "<div class='status warning'>⚠️ 未发现缓存数据,可能还未生成或已过期</div>\n";
                echo "<div class='status info'>💡 提示: 访问广场页面或运行缓存预热脚本来生成缓存</div>\n";
            }
            
            $redis->close();
        } else {
            throw new Exception("无法连接到 Redis 服务器");
        }
    } catch (Exception $e) {
        echo "<div class='status error'>❌ Redis 连接失败: " . htmlspecialchars($e->getMessage()) . "</div>\n";
        echo "<div class='status warning'>⚠️ 请检查:</div>\n";
        echo "<pre>1. Redis 服务是否运行: sudo systemctl status redis\n2. Redis 端口是否正确: 默认 6379\n3. 防火墙是否允许连接\n4. Redis 配置文件中的 bind 地址</pre>";
    }
} else {
    if (!$hasRedisExtension) {
        echo "<div class='status error'>❌ 无法测试连接: PHP Redis 扩展未安装</div>\n";
    }
    if (!$hasRedisConfig) {
        echo "<div class='status error'>❌ 无法测试连接: 配置文件缺少 Redis 配置</div>\n";
    }
}

// 4. 检查缓存类文件
echo "<h2>4️⃣ 缓存类文件检查</h2>\n";

$redisClassFile = __DIR__ . '/redis_cache.php';
$fileClassFile = __DIR__ . '/file_cache.php';
$warmupFile = __DIR__ . '/cache_warmup.php';

$files = [
    'redis_cache.php' => $redisClassFile,
    'file_cache.php' => $fileClassFile,
    'cache_warmup.php' => $warmupFile
];

foreach ($files as $name => $path) {
    if (file_exists($path)) {
        echo "<div class='status success'>✅ {$name} 存在</div>\n";
    } else {
        echo "<div class='status error'>❌ {$name} 不存在</div>\n";
    }
}

// 5. 测试缓存功能
echo "<h2>5️⃣ 缓存功能测试</h2>\n";

if (file_exists($redisClassFile)) {
    try {
        require_once $redisClassFile;
        
        if ($hasRedisConfig) {
            try {
                $cache = new RedisCache(
                    $config['redis_host'],
                    $config['redis_port'],
                    $config['redis_password'] ?? null
                );
                
                echo "<div class='status success'>✅ RedisCache 类实例化成功</div>\n";
                
                // 测试缓存读写
                $testDir = __DIR__ . '/../i/';
                if (is_dir($testDir)) {
                    echo "<div class='status info'>📝 测试缓存读写...</div>\n";
                    
                    $startTime = microtime(true);
                    $files = $cache->getFileList($testDir, '*.*');
                    $endTime = microtime(true);
                    
                    $duration = round(($endTime - $startTime) * 1000, 2);
                    
                    echo "<div class='status success'>✅ 缓存读取成功</div>\n";
                    echo "<pre>";
                    echo "读取时间: {$duration}ms\n";
                    echo "文件数量: " . count($files) . "\n";
                    echo "</pre>";
                }
                
            } catch (Exception $e) {
                echo "<div class='status error'>❌ RedisCache 实例化失败: " . htmlspecialchars($e->getMessage()) . "</div>\n";
                echo "<div class='status info'>💡 系统将自动降级到文件缓存</div>\n";
            }
        } else {
            echo "<div class='status warning'>⚠️ 跳过 RedisCache 测试: 配置文件缺少 Redis 配置</div>\n";
        }
        
    } catch (Exception $e) {
        echo "<div class='status error'>❌ 加载 RedisCache 类失败: " . htmlspecialchars($e->getMessage()) . "</div>\n";
    }
}

// 6. 当前使用的缓存类型
echo "<h2>6️⃣ 当前缓存状态</h2>\n";

// 读取缓存模式配置
$cacheMode = isset($config['plaza_cache_type']) ? (int)$config['plaza_cache_type'] : 2;
$cacheModeNames = ['关闭缓存', '文件缓存', 'Redis 缓存'];
$cacheModeName = $cacheModeNames[$cacheMode] ?? 'Redis 缓存';

echo "<div class='status info'>📋 配置模式: <strong>{$cacheModeName}</strong> (plaza_cache_type={$cacheMode})</div>\n";

if ($cacheMode === 0) {
    echo "<div class='status warning'>⚠️ 当前使用: <strong>无缓存</strong> (直接使用 glob 扫描文件)</div>\n";
} elseif ($cacheMode === 1) {
    if (file_exists($fileClassFile)) {
        echo "<div class='status success'>✅ 当前使用: <strong>文件缓存</strong></div>\n";
    } else {
        echo "<div class='status error'>❌ 文件缓存类不存在,实际使用 glob 扫描</div>\n";
    }
} else {
    // Redis 模式
    if ($hasRedisExtension && $hasRedisConfig) {
        try {
            $testRedis = new Redis();
            if (@$testRedis->connect($config['redis_host'], $config['redis_port'], 1)) {
                echo "<div class='status success'>✅ 当前使用: <strong>Redis 缓存</strong></div>\n";
                $testRedis->close();
            } else {
                throw new Exception("连接失败");
            }
        } catch (Exception $e) {
            if (file_exists($fileClassFile)) {
                echo "<div class='status warning'>⚠️ 当前使用: <strong>文件缓存</strong> (Redis 不可用,已降级)</div>\n";
            } else {
                echo "<div class='status error'>❌ 当前使用: <strong>无缓存</strong> (Redis 和文件缓存均不可用)</div>\n";
            }
        }
    } else {
        if (file_exists($fileClassFile)) {
            echo "<div class='status warning'>⚠️ 当前使用: <strong>文件缓存</strong> (Redis 未配置,已降级)</div>\n";
        } else {
            echo "<div class='status error'>❌ 当前使用: <strong>无缓存</strong> (使用原始 glob 方法)</div>\n";
        }
    }
}

// 7. 建议和操作
echo "<h2>7️⃣ 建议操作</h2>\n";

if (!$hasRedisConfig) {
    echo "<div class='status warning'>";
    echo "<h3>⚠️ 首要任务: 添加 Redis 配置</h3>";
    echo "<p>请编辑 <code>config/config.php</code>,在配置数组中添加:</p>";
    echo "<pre>'redis_host' => '127.0.0.1',\n'redis_port' => 6379,\n'redis_password' => null,</pre>";
    echo "</div>";
}

if (!$hasRedisExtension) {
    echo "<div class='status warning'>";
    echo "<h3>⚠️ 安装 PHP Redis 扩展</h3>";
    echo "<pre># Ubuntu/Debian\nsudo apt-get update\nsudo apt-get install php-redis\nsudo systemctl restart php-fpm\n\n# 验证安装\nphp -m | grep redis</pre>";
    echo "</div>";
}

echo "<div class='status info'>";
echo "<h3>💡 推荐操作流程</h3>";
echo "<ol>";
echo "<li>添加 Redis 配置到 config.php</li>";
echo "<li>安装并启动 Redis 服务</li>";
echo "<li>安装 PHP Redis 扩展</li>";
echo "<li>重启 PHP-FPM/Apache/Nginx</li>";
echo "<li>刷新本页面验证配置</li>";
echo "<li>访问广场页面或运行缓存预热脚本</li>";
echo "</ol>";
echo "</div>";

echo "</div>\n</body>\n</html>";

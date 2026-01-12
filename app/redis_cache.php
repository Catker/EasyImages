<?php
/**
 * Redis 文件缓存管理
 * 专为 NFS 环境优化,使用 Redis 缓存文件列表和统计数据
 * 
 * @author EasyImage
 * @version 1.0
 */

class RedisCache {
    private $redis;
    private $prefix = 'easyimage:files:';
    private $ttl = 1200; // 缓存20分钟（配合预加载脚本每15分钟运行）
    private $connected = false;
    
    public function __construct($host = '127.0.0.1', $port = 6379, $password = null) {
        if (!extension_loaded('redis')) {
            throw new Exception('Redis extension not installed');
        }
        
        $this->redis = new Redis();
        
        try {
            $this->redis->connect($host, $port, 2); // 2秒超时
            
            if ($password) {
                $this->redis->auth($password);
            }
            
            // 测试连接
            $this->redis->ping();
            $this->connected = true;
        } catch (Exception $e) {
            throw new Exception('Redis connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取缓存的文件列表
     * @param string $path 目录路径
     * @param string $pattern 文件模式,如 *.* 或 *.jpg
     * @return array 文件名数组
     */
    public function getFileList($path, $pattern = '*.*', $forceRefresh = false) {
        $cacheKey = $this->prefix . 'list:' . md5($path . $pattern);
        
        // 如果不是强制刷新，尝试从缓存获取
        if (!$forceRefresh) {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== false) {
                $files = json_decode($cached, true);
                $count = is_array($files) ? count($files) : 0;
                error_log(sprintf(
                    "[RedisCache] 缓存命中: %s | 文件数: %d, 数据大小: %d 字节",
                    $path,
                    $count,
                    strlen($cached)
                ));
                return $files;
            }
        }
        
        // 缓存未命中,扫描目录
        error_log("[RedisCache] 缓存未命中,开始扫描: {$path}");
        $files = $this->scanDirectory($path, $pattern);
        
        // 保存到 Redis,设置过期时间
        $jsonData = json_encode($files);
        $this->redis->setex($cacheKey, $this->ttl, $jsonData);
        
        error_log(sprintf(
            "[RedisCache] 缓存已保存: %s | 文件数: %d, 数据大小: %d 字节, TTL: %d 秒",
            $path,
            count($files),
            strlen($jsonData),
            $this->ttl
        ));
        
        return $files;
    }
    
    /**
     * 优化的目录扫描
     * 使用 scandir 替代 glob,减少网络调用
     * @param string $path 目录路径
     * @param string $pattern 文件模式
     * @return array 文件名数组
     */
    private function scanDirectory($path, $pattern) {
        if (!is_dir($path)) {
            error_log("[RedisCache] 目录不存在: {$path}");
            return [];
        }
        
        // 使用 scandir 一次性获取所有文件(比 glob 快)
        $entries = @scandir($path);
        if (!$entries) {
            error_log("[RedisCache] scandir 失败: {$path}");
            return [];
        }
        
        $files = [];
        $extensions = $this->parsePattern($pattern);
        $totalEntries = count($entries);
        $processedFiles = 0;
        $skippedDirs = 0;
        
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            
            $fullPath = $path . '/' . $entry;
            
            // 只检查一次文件类型(减少 stat 调用)
            if (@is_file($fullPath)) {
                // 如果有扩展名限制,进行过滤
                if (empty($extensions) || $this->matchExtension($entry, $extensions)) {
                    $files[] = $entry;
                    $processedFiles++;
                }
            } else if (@is_dir($fullPath)) {
                $skippedDirs++;
            }
        }
        
        // 记录扫描统计
        error_log(sprintf(
            "[RedisCache] 扫描完成: %s | 总条目: %d, 文件: %d, 目录: %d, 匹配: %d",
            $path,
            $totalEntries - 2, // 减去 . 和 ..
            $processedFiles,
            $skippedDirs,
            count($files)
        ));
        
        return $files;
    }
    
    /**
     * 获取文件数量(带缓存)
     * @param string $path 目录路径
     * @param bool $recursive 是否递归统计
     * @return int 文件数量
     */
    public function getFileCount($path, $recursive = false, $forceRefresh = false) {
        // 对于非递归情况,直接使用文件列表的 count,避免重复扫描
        if (!$recursive) {
            $cacheKey = $this->prefix . 'count:' . md5($path);
            
            // 如果不是强制刷新，先尝试从 count 缓存获取
            if (!$forceRefresh) {
                $cached = $this->redis->get($cacheKey);
                if ($cached !== false) {
                    return (int)$cached;
                }
            }
            
            // count 缓存未命中或强制刷新，从 list 缓存获取并计数
            // 注意：不传递 forceRefresh 给 getFileList，避免双重扫描
            // 如果需要刷新 list，应该先单独调用 getFileList
            $files = $this->getFileList($path, '*.*');
            $count = count($files);
            
            // 保存 count 缓存（覆盖写入，重置 TTL）
            $this->redis->setex($cacheKey, $this->ttl, $count);
            
            return $count;
        }
        
        // 递归情况,使用原有逻辑
        $cacheKey = $this->prefix . 'count:' . md5($path . ':r');
        
        // 尝试从缓存获取
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return (int)$cached;
        }
        
        // 重新计算
        $count = $this->countFiles($path, true);
        
        // 保存到 Redis
        $this->redis->setex($cacheKey, $this->ttl, $count);
        
        return $count;
    }
    
    /**
     * 优化的文件计数
     * @param string $path 目录路径
     * @param bool $recursive 是否递归
     * @return int 文件数量
     */
    private function countFiles($path, $recursive) {
        if (!is_dir($path)) {
            return 0;
        }
        
        $count = 0;
        $entries = @scandir($path);
        
        if (!$entries) {
            return 0;
        }
        
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            
            $fullPath = $path . '/' . $entry;
            
            if (@is_file($fullPath)) {
                $count++;
            } elseif ($recursive && @is_dir($fullPath)) {
                $count += $this->countFiles($fullPath, true);
            }
        }
        
        return $count;
    }
    
    /**
     * 批量预热缓存
     * @param array $paths 路径数组
     * @return array 预热结果
     */
    public function warmupCache($paths) {
        $results = [];
        
        foreach ($paths as $path) {
            if (is_dir($path)) {
                // 预热文件列表
                $files = $this->getFileList($path, '*.*');
                
                // 预热文件数量
                $count = $this->getFileCount($path, false);
                
                $results[$path] = [
                    'files' => count($files),
                    'count' => $count
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * 清除缓存
     * @param string|null $path 指定路径或 null 清除所有
     */
    public function clearCache($path = null) {
        if ($path === null) {
            // 清除所有 EasyImage 相关缓存
            $keys = $this->redis->keys($this->prefix . '*');
            if ($keys) {
                $this->redis->del($keys);
            }
        } else {
            // 清除特定路径的缓存
            $pattern = $this->prefix . '*' . md5($path) . '*';
            $keys = $this->redis->keys($pattern);
            if ($keys) {
                $this->redis->del($keys);
            }
        }
    }
    
    /**
     * 获取缓存统计
     * @return array 统计信息
     */
    public function getStats() {
        $keys = $this->redis->keys($this->prefix . '*');
        $info = $this->redis->info();
        
        return [
            'total_keys' => count($keys),
            'memory_usage' => $info['used_memory_human'] ?? 'N/A',
            'hits' => $info['keyspace_hits'] ?? 0,
            'misses' => $info['keyspace_misses'] ?? 0,
        ];
    }
    
    /**
     * 设置缓存时间
     * @param int $seconds 缓存秒数
     */
    public function setTTL($seconds) {
        $this->ttl = $seconds;
    }
    
    /**
     * 解析 glob 模式为扩展名列表
     * @param string $pattern 模式字符串
     * @return array 扩展名数组
     */
    private function parsePattern($pattern) {
        // *.* 或 * 表示所有文件
        if ($pattern === '*.*' || $pattern === '*') {
            return [];
        }
        
        // *.jpg 格式
        if (preg_match('/^\*\.([a-z0-9]+)$/i', $pattern, $matches)) {
            return [$matches[1]];
        }
        
        // *.{jpg,png,gif} 格式
        if (preg_match('/^\*\.{([^}]+)}$/i', $pattern, $matches)) {
            return explode(',', $matches[1]);
        }
        
        return [];
    }
    
    /**
     * 检查文件扩展名是否匹配
     * @param string $filename 文件名
     * @param array $extensions 扩展名数组
     * @return bool 是否匹配
     */
    private function matchExtension($filename, $extensions) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, array_map('strtolower', $extensions));
    }
    
    /**
     * 检查是否已连接
     * @return bool
     */
    public function isConnected() {
        return $this->connected;
    }
}

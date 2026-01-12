<?php
/**
 * 文件缓存管理(用于 NFS 优化)
 * 当 Redis 不可用时的备选方案
 * 
 * @author EasyImage
 * @version 1.0
 */

class FileCache {
    private $cacheDir;
    private $cacheTime = 1200; // 缓存20分钟（配合预加载脚本每15分钟运行）
    
    public function __construct($cacheDir = null) {
        global $config;
        $this->cacheDir = $cacheDir ?: APP_ROOT . '/app/cache/files/';
        
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * 获取缓存的文件列表
     * @param string $path 目录路径
     * @param string $pattern 文件模式
     * @return array 文件名数组
     */
    public function getFileList($path, $pattern = '*.*', $forceRefresh = false) {
        $cacheKey = md5($path . $pattern);
        $cacheFile = $this->cacheDir . $cacheKey . '.json';
        
        // 如果不是强制刷新，检查缓存
        if (!$forceRefresh && file_exists($cacheFile)) {
            $cacheAge = time() - filemtime($cacheFile);
            if ($cacheAge < $this->cacheTime) {
                $data = json_decode(file_get_contents($cacheFile), true);
                return $data['files'] ?? [];
            }
        }
        
        // 缓存未命中,重新扫描
        $files = $this->scanDirectory($path, $pattern);
        
        // 保存缓存
        @file_put_contents($cacheFile, json_encode([
            'files' => $files,
            'time' => time()
        ]));
        
        return $files;
    }
    
    /**
     * 优化的目录扫描
     * @param string $path 目录路径
     * @param string $pattern 文件模式
     * @return array 文件名数组
     */
    private function scanDirectory($path, $pattern) {
        if (!is_dir($path)) {
            return [];
        }
        
        // 使用 scandir 一次性获取所有文件
        $entries = @scandir($path);
        if (!$entries) {
            return [];
        }
        
        $files = [];
        $extensions = $this->parsePattern($pattern);
        
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            
            $fullPath = $path . '/' . $entry;
            
            // 只检查一次文件类型
            if (@is_file($fullPath)) {
                if (empty($extensions) || $this->matchExtension($entry, $extensions)) {
                    $files[] = $entry;
                }
            }
        }
        
        return $files;
    }
    
    /**
     * 获取文件数量(带缓存)
     * @param string $path 目录路径
     * @param bool $recursive 是否递归
     * @return int 文件数量
     */
    public function getFileCount($path, $recursive = false, $forceRefresh = false) {
        // 对于非递归情况，直接使用文件列表的 count，避免重复扫描
        // 与 redis_cache.php 的逻辑保持一致
        if (!$recursive) {
            $cacheKey = md5($path);
            $cacheFile = $this->cacheDir . $cacheKey . '_count.txt';
            
            // 如果不是强制刷新，先尝试从 count 缓存获取
            if (!$forceRefresh && file_exists($cacheFile)) {
                $cacheAge = time() - filemtime($cacheFile);
                if ($cacheAge < $this->cacheTime) {
                    return (int)file_get_contents($cacheFile);
                }
            }
            
            // count 缓存未命中或强制刷新，从 list 缓存获取并计数
            // 注意：不传递 forceRefresh 给 getFileList，避免双重扫描
            $files = $this->getFileList($path, '*.*');
            $count = count($files);
            
            // 保存 count 缓存（覆盖写入，重置 TTL）
            @file_put_contents($cacheFile, $count);
            
            return $count;
        }
        
        // 递归情况，使用原有逻辑
        $cacheKey = md5($path . '_recursive');
        $cacheFile = $this->cacheDir . $cacheKey . '_count.txt';
        
        // 检查缓存
        if (file_exists($cacheFile)) {
            $cacheAge = time() - filemtime($cacheFile);
            if ($cacheAge < $this->cacheTime) {
                return (int)file_get_contents($cacheFile);
            }
        }
        
        // 重新计算
        $count = $this->countFiles($path, true);
        
        // 保存缓存
        @file_put_contents($cacheFile, $count);
        
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
                $files = $this->getFileList($path, '*.*');
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
            // 清除所有缓存
            $files = glob($this->cacheDir . '*');
            foreach ($files as $file) {
                @unlink($file);
            }
        } else {
            // 清除特定路径的缓存
            $cacheKey = md5($path);
            $files = glob($this->cacheDir . $cacheKey . '*');
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
    
    /**
     * 设置缓存时间
     * @param int $seconds 缓存秒数
     */
    public function setTTL($seconds) {
        $this->cacheTime = $seconds;
    }
    
    /**
     * 解析 glob 模式为扩展名列表
     */
    private function parsePattern($pattern) {
        if ($pattern === '*.*' || $pattern === '*') {
            return [];
        }
        
        if (preg_match('/^\*\.([a-z0-9]+)$/i', $pattern, $matches)) {
            return [$matches[1]];
        }
        
        if (preg_match('/^\*\.{([^}]+)}$/i', $pattern, $matches)) {
            return explode(',', $matches[1]);
        }
        
        return [];
    }
    
    /**
     * 检查文件扩展名是否匹配
     */
    private function matchExtension($filename, $extensions) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, array_map('strtolower', $extensions));
    }
}

<?php

/**
 * 广场目录列表缓存(用于 NFS 优化)
 *
 * 缓存键包含目录 mtime：目录内文件增删会改变 mtime，旧键自动失效，
 * 无需在上传/删除处手动清缓存，也无需预热脚本。读缓存只需一次 stat。
 *
 * plaza_cache_type: 0=关闭 1=文件缓存 2=Redis(不可用时降级为文件缓存)
 */

class PlazaCache
{
    const PREFIX = 'easyimage:plaza:';
    const TTL = 86400;

    /** @var Redis|null */
    private $redis = null;
    private $fileDir = null;

    public static function instance()
    {
        static $instance = null;
        if ($instance === null) {
            global $config;
            $instance = new self(isset($config['plaza_cache_type']) ? (int)$config['plaza_cache_type'] : 2, $config);
        }
        return $instance;
    }

    private function __construct($mode, $config)
    {
        if ($mode === 2 && extension_loaded('redis')) {
            try {
                $redis = new Redis();
                // 短超时：Redis 宕机时每个请求最多多等 0.2s
                if ($redis->connect($config['redis_host'] ?? '127.0.0.1', $config['redis_port'] ?? 6379, 0.2)) {
                    if (empty($config['redis_password']) || $redis->auth($config['redis_password'])) {
                        $this->redis = $redis;
                    }
                }
            } catch (Exception $e) {
            }
        }
        if ($mode >= 1 && $this->redis === null) {
            $this->fileDir = APP_ROOT . '/app/cache/files/';
            if (!is_dir($this->fileDir)) @mkdir($this->fileDir, 0755, true);
        }
    }

    /** 当前实际使用的缓存后端: redis / file / none */
    public function backend()
    {
        return $this->redis ? 'redis' : ($this->fileDir ? 'file' : 'none');
    }

    /**
     * 获取目录下的文件名列表(非递归，按文件名升序)
     * @param string $dir 绝对路径
     * @return array
     */
    public function files($dir)
    {
        $dir = rtrim($dir, '/');
        $mtime = @filemtime($dir);
        if ($mtime === false) return [];

        $id = md5($dir);
        $key = $id . '_' . $mtime;
        $cached = $this->get($key);
        if ($cached !== null) return $cached;

        $entries = @scandir($dir);
        // 日期目录下只有图片文件，按“含扩展名”过滤即可，避免逐个 is_file 产生 N 次 NFS stat
        $files = $entries ? array_values(array_filter($entries, function ($f) {
            return strpos($f, '.') > 0;
        })) : [];

        // mtime 只有秒级精度，同一秒内的后续写入不会改变键，刚修改过的目录不缓存
        if (time() - $mtime > 1) $this->set($id, $key, $files);

        return $files;
    }

    private function get($key)
    {
        if ($this->redis) {
            $data = $this->redis->get(self::PREFIX . $key);
        } elseif ($this->fileDir) {
            $data = @file_get_contents($this->fileDir . $key . '.json');
        } else {
            return null;
        }
        return $data === false ? null : json_decode($data, true);
    }

    private function set($id, $key, $files)
    {
        $data = json_encode($files);
        if ($this->redis) {
            // 旧键由 TTL 自然过期
            $this->redis->setex(self::PREFIX . $key, self::TTL, $data);
        } elseif ($this->fileDir) {
            foreach (glob($this->fileDir . $id . '_*.json') ?: [] as $old) @unlink($old);
            @file_put_contents($this->fileDir . $key . '.json', $data);
        }
    }
}

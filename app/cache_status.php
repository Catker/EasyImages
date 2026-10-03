<?php

/** 广场缓存状态 */
require_once __DIR__ . '/header.php';

if (!is_who_login('admin')) exit('<div class="alert alert-danger">此页面需要管理员权限</div>');

$cacheModeNames = ['关闭缓存', '文件缓存', 'Redis 缓存'];
$cacheMode = isset($config['plaza_cache_type']) ? (int)$config['plaza_cache_type'] : 2;
$plaza = PlazaCache::instance();
$todayDir = APP_ROOT . $config['path'] . date('Y/m/d/');

$start = microtime(true);
$count = count($plaza->files($todayDir));
$elapsed = round((microtime(true) - $start) * 1000, 2);
?>
<div class="row">
  <div class="col-md-12">
    <h3>广场缓存状态</h3>
    <table class="table table-bordered">
      <tr><th style="width: 160px;">配置模式</th><td><?php echo $cacheModeNames[$cacheMode] ?? $cacheMode; ?> (plaza_cache_type=<?php echo $cacheMode; ?>)</td></tr>
      <tr><th>实际后端</th><td><?php echo $plaza->backend(); ?><?php if ($cacheMode === 2 && $plaza->backend() !== 'redis') echo ' <span class="text-danger">(Redis 不可用，已降级)</span>'; ?></td></tr>
      <tr><th>Redis 扩展</th><td><?php echo extension_loaded('redis') ? phpversion('redis') : '未安装'; ?></td></tr>
      <tr><th>Redis 地址</th><td><?php echo htmlspecialchars(($config['redis_host'] ?? '127.0.0.1') . ':' . ($config['redis_port'] ?? 6379)); ?></td></tr>
      <tr><th>今日目录读取</th><td><?php echo "{$count} 个文件，耗时 {$elapsed}ms"; ?></td></tr>
    </table>
    <p class="text-muted">缓存键包含目录 mtime，上传/删除后自动失效，无需预热或手动清理。</p>
  </div>
</div>
<?php require_once __DIR__ . '/footer.php';

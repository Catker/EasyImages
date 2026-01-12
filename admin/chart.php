<?php
/*
 * 统计中心 (异步版)
 */
require_once '../app/header.php';
// 保持对 app/chart.php 的引用以防有其他副作用，但数据加载将转为异步
require_once APP_ROOT . '/app/chart.php';

// 检测登录和是否开启统计
if (!$config['chart_on'] || !is_who_login('admin')) exit(header('Location: ' . $config['domain'] . '?hart#closed'));

// 删除统计文件 (保留原有逻辑，虽然异步刷新可能不再强依赖手动删除)
if (isset($_POST['del_total'])) {
    @deldir($_POST['del_total']);
    echo '
		<script>
		new $.zui.Messager("缓存清理成功!", {
			type: "success", 
			icon: "ok-sign"
		}).show();
		</script>
		';
}
?>
<style>
    .autoshadow {
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.3), 0 0 40px rgba(0, 0, 0, 0.1);
        border: 1px;
        margin: 0px 0px 10px 10px;
        width: 90px;
        height: 80px;
        text-align: center;
        transition: all 0.3s;
    }

    .autoshadow:hover {
        transform: scale(1.05);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        z-index: 10;
    }
    
    .data-value {
        font-weight: bold;
    }
    
    .loading-placeholder {
        color: #999;
        font-size: 12px;
    }
    
    #progress-container {
        display: none;
        margin-top: 10px;
    }
</style>

<div class="row">
    <div class="clo-md-12">
        <div class="alert alert-warning">
            <div class="row">
                <div class="col-md-6">
                    <span>统计时间: <span id="stat-time"><i class="icon icon-spin icon-spinner"></i> 加载中...</span></span>
                </div>
                <div class="col-md-6 text-right">
                    <button id="btn-refresh" class="btn btn-mini btn-primary"><i class="icon icon-refresh"></i> 重新统计</button>
                    <!-- 保留清理缓存功能 -->
                    <form action="chart.php" method="post" style="display:inline-block; margin-left: 5px;" onsubmit="return confirm('确定要删除所有统计缓存吗？删除后将重新计算。');">
                        <input type="hidden" name="del_total" value="<?php echo APP_ROOT . '/admin/logs/counts/'; ?>">
                        <button class="btn btn-mini btn-danger"><i class="icon icon-trash"></i> 清理缓存</button>
                    </form>
                </div>
            </div>
            
            <!-- 进度条容器 -->
            <div id="progress-container">
                <div class="progress progress-striped active">
                    <div id="stat-progress-bar" class="progress-bar progress-bar-success" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%">
                        <span class="sr-only">0% Complete</span>
                    </div>
                </div>
                <div id="stat-message" class="text-info small">准备开始...</div>
            </div>
        </div>
    </div>
    
    <div class="col-md-12 col-xs-12" id="data-cards">
        <div class="col-xs-3 alert alert-success autoshadow">今日上传
            <hr />
            <span id="today-upload" class="data-value"><i class="icon icon-spin icon-spinner loading-placeholder"></i></span> 张
        </div>
        <div class="col-xs-3 alert alert-success autoshadow">昨日上传
            <hr />
            <span id="yesterday-upload" class="data-value"><i class="icon icon-spin icon-spinner loading-placeholder"></i></span> 张
        </div>
        <div class="col-xs-3 alert alert-primary autoshadow">
            累计上传
            <hr />
            <span id="total-upload" class="data-value"><i class="icon icon-spin icon-spinner loading-placeholder"></i></span> 张
        </div>
        <div class="col-xs-3 alert alert-primary autoshadow">
            缓存文件
            <hr />
            <span id="cache-files" class="data-value"><i class="icon icon-spin icon-spinner loading-placeholder"></i></span> 张
        </div>
        <div class="col-xs-3 alert alert-primary autoshadow">
            可疑图片
            <hr />
            <span id="suspicious-files" class="data-value"><i class="icon icon-spin icon-spinner loading-placeholder"></i></span> 张
        </div>
        <div class="col-xs-3 alert alert-primary autoshadow">
            文件夹
            <hr />
            <span id="folder-count" class="data-value"><i class="icon icon-spin icon-spinner loading-placeholder"></i></span> 个
        </div>
        <div class="col-xs-3 alert alert-primary autoshadow">
            总空间
            <hr />
            <span id="total-space" class="data-value"><i class="icon icon-spin icon-spinner loading-placeholder"></i></span>
        </div>
        <div class="col-xs-3 alert alert-primary autoshadow">
            已用空间
            <hr />
            <span id="used-space" class="data-value"><i class="icon icon-spin icon-spinner loading-placeholder"></i></span>
        </div>
        <div class="col-xs-3 alert alert-primary autoshadow">
            剩余空间
            <hr />
            <span id="free-space" class="data-value"><i class="icon icon-spin icon-spinner loading-placeholder"></i></span>
        </div>
        <div class="col-xs-3 alert alert-primary autoshadow">
            图片占用
            <hr />
            <span id="image-usage" class="data-value"><i class="icon icon-spin icon-spinner loading-placeholder"></i></span>
        </div>
        <div class="col-xs-3 alert alert-primary autoshadow">
            当前版本
            <hr />
            <?php echo APP_VERSION; ?>
        </div>
    </div>
    
    <div class="col-md-12 col-xs-12">
        <hr />
        <div class="col-md-6 col-xs-12">
            <span>硬盘使用量</span>
            <div id="Piedisk" style="width:350px;height: 350px;"></div>
        </div>
        <div class="col-md-6 col-xs-12">
            <div id="myPieChart" style="width:350px;height: 350px;"></div>
        </div>
    </div>
    
    <div class="col-md-12 col-xs-12">
        <hr />
        <span>最近30日上传趋势与空间占用</span>
        <div id="myLineChart" style="width: 100%;height: 300px;"></div>
    </div>
</div>

<script src="<?php static_cdn(); ?>/public/static/echarts/echarts.min.js"></script>
<script>
    // 初始化图表实例
    var gaugeChart = echarts.init(document.getElementById('Piedisk'));
    var pieChart = echarts.init(document.getElementById('myPieChart'));
    var lineChart = echarts.init(document.getElementById('myLineChart'));
    
    // 图表配置 - 仪表盘
    var gaugeOption = {
        tooltip: { formatter: "{a} <br/>{b} : {c}%" },
        series: [{
            name: '硬盘使用量',
            type: 'gauge',
            detail: { valueAnimation: true, formatter: '{value}%' },
            data: [{ value: 0, name: '已使用' }]
        }]
    };
    
    // 图表配置 - 饼图
    var pieOption = {
        color: ['#38B03F', '#353535'],
        title: { left: 'center' },
        tooltip: { trigger: 'item', formatter: '{a} <br/>{b} : {c} GB ({d}%)' },
        legend: { orient: 'vertical', left: 'left', data: ['剩余空间', '已用空间'] },
        series: [{
            name: '硬盘使用:',
            type: 'pie',
            radius: '55%',
            center: ['50%', '60%'],
            data: [
                { value: 0, name: '剩余空间' },
                { value: 0, name: '已用空间' }
            ],
            emphasis: { itemStyle: { shadowBlur: 10, shadowOffsetX: 0, shadowColor: 'rgba(0, 0, 0, 0.5)' } }
        }]
    };
    
    // 图表配置 - 折线图
    var lineOption = {
        color: ['#EA644A', '#38B03F'],
        tooltip: { trigger: 'axis', axisPointer: { type: 'cross', label: { backgroundColor: '#6a7985' } } },
        legend: { data: ['上传', '占用'] },
        toolbox: { feature: { saveAsImage: {} } },
        grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
        xAxis: { type: 'category', boundaryGap: false, data: [] },
        yAxis: { type: 'value', boundaryGap: [0, '100%'] },
        dataZoom: [
            { type: 'inside', start: 50, end: 100 },
            { start: 0, end: 10 }
        ],
        series: [
            { name: '占用', type: 'line', stack: 'x', smooth: true, areaStyle: {}, emphasis: { focus: 'series' }, data: [] },
            { name: '上传', type: 'line', stack: 'x', smooth: true, areaStyle: {}, emphasis: { focus: 'series' }, data: [] }
        ]
    };

    // 应用初始配置
    gaugeChart.setOption(gaugeOption);
    pieChart.setOption(pieOption);
    lineChart.setOption(lineOption);
    
    // 窗口调整时重绘
    window.onresize = function() {
        lineChart.resize();
        gaugeChart.resize();
        pieChart.resize();
    };

    // 饼图自动轮播效果
    let currentIndex = -1;
    setInterval(function() {
        var dataLen = pieOption.series[0].data.length;
        pieChart.dispatchAction({ type: 'downplay', seriesIndex: 0, dataIndex: currentIndex });
        currentIndex = (currentIndex + 1) % dataLen;
        pieChart.dispatchAction({ type: 'highlight', seriesIndex: 0, dataIndex: currentIndex });
        pieChart.dispatchAction({ type: 'showTip', seriesIndex: 0, dataIndex: currentIndex });
    }, 1000);

    document.title = "图床统计信息 - <?php echo $config['title']; ?>";

    // ------------------------------------------------------------
    // 异步数据加载逻辑
    // ------------------------------------------------------------
    
    $(document).ready(function() {
        // 加载初始数据
        loadStats();
        
        // 绑定刷新按钮
        $('#btn-refresh').click(function() {
            startRefresh();
        });
    });
    
    function loadStats() {
        $.getJSON('../app/stat_async.php?action=status', function(res) {
            if (res.success) {
                // 检查是否有缓存数据
                if (!res.cached || !res.data) {
                    // 没有缓存数据,自动触发统计
                    new $.zui.Messager("检测到无统计数据,正在自动统计...", {type: "info"}).show();
                    setTimeout(function() {
                        startRefresh();
                    }, 500);
                } else {
                    // 有缓存数据,正常显示
                    updateUI(res);
                }
            } else {
                new $.zui.Messager(res.error || "加载失败", {type: "danger"}).show();
            }
        }).fail(function() {
            new $.zui.Messager("网络请求失败", {type: "danger"}).show();
        });
    }
    
    function updateUI(res) {
        // 更新基础数据
        if (res.data) {
            $('#stat-time').text(res.data.total_time || '未知');
            $('#today-upload').text(res.data.todayUpload || 0);
            $('#yesterday-upload').text(res.data.yestUpload || 0);
            $('#total-upload').text(res.data.filenum || 0);
            $('#folder-count').text(res.data.dirnum || 0);
            $('#image-usage').text(res.data.usage_space || '0 B');
        } else {
            // 没有数据时显示默认值
            $('#stat-time').html('<span class="text-muted">暂无数据</span>');
            $('#today-upload').text('0');
            $('#yesterday-upload').text('0');
            $('#total-upload').text('0');
            $('#folder-count').text('0');
            $('#image-usage').text('0 B');
        }
        
        // 更新快速数据
        if (res.quick) {
            $('#cache-files').text(res.quick.cache || 0);
            $('#suspicious-files').text(res.quick.suspic || 0);
        }
        
        // 更新磁盘信息
        if (res.disk) {
            $('#total-space').text(res.disk.total);
            $('#used-space').text(res.disk.used);
            $('#free-space').text(res.disk.free);
            
            // 更新仪表盘
            gaugeChart.setOption({
                series: [{
                    data: [{ value: res.disk.percent, name: '已使用' }]
                }]
            });
            
            // 更新饼图
            pieChart.setOption({
                series: [{
                    data: [
                        { value: parseFloat(res.disk.free.replace(/[^\d.]/g, '')), name: '剩余空间' },
                        { value: parseFloat(res.disk.used.replace(/[^\d.]/g, '')), name: '已用空间' }
                    ]
                }]
            });
        }
        
        // 更新趋势图
        if (res.chart && res.chart.dates && res.chart.dates.length > 0) {
            lineChart.setOption({
                xAxis: { data: res.chart.dates },
                series: [
                    { data: res.chart.disks },
                    { data: res.chart.numbers }
                ]
            });
        }
    }
    
    // ------------------------------------------------------------
    // 统计刷新与进度逻辑
    // ------------------------------------------------------------
    
    let pollTimer = null;
    
    function startRefresh() {
        $('#btn-refresh').prop('disabled', true).html('<i class="icon icon-spin icon-spinner"></i> 正在请求...');
        $('#progress-container').slideDown();
        
        $.ajax({
            url: '../app/stat_async.php?action=refresh',
            type: 'GET',
            dataType: 'json',
            timeout: 60000, // 60秒超时(fastcgi_finish_request 可用时通常1-2秒返回,不可用时需要更长时间)
            success: function(res) {
                if (res.success) {
                    // 如果直接完成（例如已在运行且接近完成，或者非常快），直接更新
                    if (res.data) {
                         updateUI({ data: res.data }); // 局部更新
                    }
                    startPolling();
                } else if (res.error === '统计任务正在进行中') {
                    new $.zui.Messager("任务已在后台运行", {type: "warning"}).show();
                    startPolling();
                } else {
                    new $.zui.Messager(res.error || "启动失败", {type: "danger"}).show();
                    resetRefreshState();
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = "请求启动失败";
                if (xhr.responseText) {
                    try {
                        let response = JSON.parse(xhr.responseText);
                        errorMsg = response.error || errorMsg;
                    } catch(e) {
                        // 如果不是 JSON,显示原始错误
                        errorMsg += ": " + (xhr.responseText.substring(0, 100) || error || status);
                    }
                } else {
                    errorMsg += ": " + (error || status || "网络错误");
                }
                new $.zui.Messager(errorMsg, {type: "danger"}).show();
                resetRefreshState();
            }
        });
    }
    
    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        
        $('#btn-refresh').prop('disabled', true).html('<i class="icon icon-spin icon-spinner"></i> 统计中...');
        
        pollTimer = setInterval(function() {
            $.getJSON('../app/stat_async.php?action=progress', function(res) {
                if (res.success && res.progress) {
                    const p = res.progress;
                    const percent = p.percent || 0;
                    
                    // 更新进度条
                    $('#stat-progress-bar').css('width', percent + '%').attr('aria-valuenow', percent);
                    $('#stat-progress-bar').html(percent + '%');
                    $('#stat-message').text(p.message || '处理中...');
                    
                    // 检查状态
                    if (p.status === 'completed') {
                        clearInterval(pollTimer);
                        new $.zui.Messager("统计完成！", {type: "success"}).show();
                        setTimeout(function() {
                            loadStats(); // 重新加载完整数据
                            resetRefreshState();
                        }, 1000);
                    } else if (p.status === 'error') {
                        clearInterval(pollTimer);
                        new $.zui.Messager("统计出错: " + p.message, {type: "danger"}).show();
                        resetRefreshState();
                    }
                }
            });
        }, 1000); // 1秒轮询一次
    }
    
    function resetRefreshState() {
        $('#btn-refresh').prop('disabled', false).html('<i class="icon icon-refresh"></i> 重新统计');
        setTimeout(function() {
            $('#progress-container').slideUp();
            // 重置进度条
            $('#stat-progress-bar').css('width', '0%').text('');
        }, 2000);
    }

</script>


<?php require_once APP_ROOT . '/app/footer.php';

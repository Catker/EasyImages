<?php
/**
 * 批量删除工具 - 支持多格式链接解析
 * 支持格式：直链 / Markdown / 论坛 BBCode / HTML 标签
 * 粘贴包含图片链接的文本，自动解析并批量删除
 */

require_once __DIR__ . '/../app/function.php';

// 检查管理员登录
if (!is_who_login('admin')) {
    echo '<script>alert("请使用管理员账户登录!");window.location.href="/admin/index.php";</script>';
    exit;
}

require_once __DIR__ . '/../app/header.php';
?>

<div class="row">
    <div class="col-md-12">
        <div class="panel">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="icon icon-trash"></i> 批量删除工具</h3>
            </div>
            <div class="panel-body">
                <div class="alert alert-warning">
                    <i class="icon icon-info-sign"></i>
                    <strong>使用说明：</strong>
                    <ul style="margin-top: 10px;">
                        <li>粘贴包含图片链接的文本，自动解析多种格式</li>
                        <li>支持格式：
                            <ul style="margin-top: 5px; margin-bottom: 0;">
                                <li><strong>直链</strong>：<code>https://yoursite.com/i/image.jpg</code></li>
                                <li><strong>Markdown</strong>：<code>![alt](url)</code> / <code>[text](url)</code></li>
                                <li><strong>论坛代码</strong>：<code>[img]url[/img]</code> / <code>[url=...]...[/url]</code></li>
                                <li><strong>HTML</strong>：<code>&lt;img src="url"&gt;</code> / <code>&lt;a href="url"&gt;</code></li>
                            </ul>
                        </li>
                        <li>仅删除本站图片，外站链接会被忽略</li>
                    </ul>
                </div>

                <div class="form-group">
                    <label for="inputLinks">粘贴链接内容：</label>
                    <textarea class="form-control" id="inputLinks" rows="10" placeholder="支持多种格式，示例：

▸ 直链：https://yoursite.com/i/2024/01/01/image.jpg
▸ Markdown：![描述](https://yoursite.com/i/2024/01/01/image.png)
▸ 论坛代码：[img]https://yoursite.com/i/2024/01/01/image.jpg[/img]
▸ HTML：<img src=&quot;https://yoursite.com/i/2024/01/01/image.webp&quot;>

可混合粘贴，自动识别所有图片链接"></textarea>
                </div>

                <div class="btn-toolbar">
                    <button type="button" class="btn btn-primary" onclick="parseLinks()">
                        <i class="icon icon-search"></i> 解析链接
                    </button>
                    <button type="button" class="btn btn-default" onclick="clearAll()">
                        <i class="icon icon-remove"></i> 清空
                    </button>
                </div>

                <hr>

                <div id="parseResult" style="display: none;">
                    <h5>解析结果 <span class="label label-info" id="urlCount">0</span> 个链接</h5>
                    
                    <div class="btn-toolbar" style="margin-bottom: 15px;">
                        <button type="button" class="btn btn-mini" onclick="selectAll()">全选</button>
                        <button type="button" class="btn btn-mini" onclick="selectNone()">取消</button>
                        <button type="button" class="btn btn-mini" onclick="selectReverse()">反选</button>
                        <button type="button" class="btn btn-danger" onclick="batchDelete()">
                            <i class="icon icon-trash"></i> 删除选中
                        </button>
                        <button type="button" class="btn btn-warning" onclick="batchRecycle()">
                            <i class="icon icon-undo"></i> 回收选中
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-striped table-bordered" id="urlTable">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"><input type="checkbox" id="checkAll" onchange="toggleAll(this)"></th>
                                    <th style="width: 80px;">预览</th>
                                    <th>链接地址</th>
                                    <th style="width: 100px;">状态</th>
                                </tr>
                            </thead>
                            <tbody id="urlList">
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="deleteLog" style="display: none; margin-top: 20px;">
                    <h5>执行日志</h5>
                    <pre id="logContent" style="max-height: 300px; overflow-y: auto; background: #f5f5f5; padding: 10px;"></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?php static_cdn(); ?>/public/static/zui/lib/bootbox/bootbox.min.css">
<script src="<?php static_cdn(); ?>/public/static/zui/lib/bootbox/bootbox.min.js"></script>

<script>
// 站点域名，用于判断是否本站链接
const siteDomain = '<?php echo $config['domain']; ?>';
const imgPath = '<?php echo $config['path']; ?>';

// 存储解析出的URL
let parsedUrls = [];

// 解析链接
function parseLinks() {
    const input = document.getElementById('inputLinks').value;
    if (!input.trim()) {
        new $.zui.Messager("请先粘贴包含链接的内容！", {type: "warning", icon: "exclamation-sign"}).show();
        return;
    }

    // 正则匹配各种格式
    const patterns = [
        // Markdown: ![alt](url) 和 [text](url)
        /!\[[^\]]*\]\(([^)\s]+)\)/g,
        /\[[^\]]*\]\(([^)\s]+)\)/g,
        // 论坛 BBCode: [img]url[/img]
        /\[img\]([^\[]+)\[\/img\]/gi,
        // 论坛 BBCode: [url=...]...[/url] 或 [url]...[/url]
        /\[url=([^\]]+)\][^\[]*\[\/url\]/gi,
        /\[url\]([^\[]+)\[\/url\]/gi,
        // HTML: <img src="url"> 或 <img src='url'>
        /<img[^>]+src\s*=\s*["']([^"']+)["'][^>]*>/gi,
        // HTML: <a href="url"> 或 <a href='url'>
        /<a[^>]+href\s*=\s*["']([^"']+)["'][^>]*>/gi,
        // 纯 URL（放最后作为兜底）
        /(https?:\/\/[^\s<>"'\)\]\[]+)/g
    ];

    let urls = new Set();
    
    patterns.forEach(pattern => {
        let match;
        // 重置正则的 lastIndex
        pattern.lastIndex = 0;
        while ((match = pattern.exec(input)) !== null) {
            let url = match[1] || match[0];
            // 清理 URL 末尾可能的标点
            url = url.replace(/[,;:!?。，；：！？]+$/, '');
            // 检查是否为图片格式
            if (/\.(jpg|jpeg|png|gif|webp|bmp|ico|svg|avif|heic|tiff?)(\?.*)?$/i.test(url)) {
                urls.add(url);
            }
        }
    });

    parsedUrls = Array.from(urls);
    
    if (parsedUrls.length === 0) {
        new $.zui.Messager("未解析到任何图片链接！", {type: "danger", icon: "exclamation-sign"}).show();
        return;
    }

    // 显示解析结果
    renderUrlList();
    document.getElementById('parseResult').style.display = 'block';
    document.getElementById('urlCount').textContent = parsedUrls.length;
    
    new $.zui.Messager(`成功解析 ${parsedUrls.length} 个图片链接`, {type: "success", icon: "ok-sign"}).show();
}

// 渲染 URL 列表
function renderUrlList() {
    const tbody = document.getElementById('urlList');
    tbody.innerHTML = '';
    
    parsedUrls.forEach((url, index) => {
        const isLocal = url.includes(siteDomain) || url.startsWith('/');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="checkbox" class="url-checkbox" data-index="${index}" ${isLocal ? 'checked' : ''} ${!isLocal ? 'disabled' : ''}></td>
            <td><img src="${url}" style="max-width: 60px; max-height: 60px;" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2260%22 height=%2260%22><text x=%2210%22 y=%2235%22 fill=%22%23999%22>Error</text></svg>'"></td>
            <td style="word-break: break-all; font-size: 12px;">${escapeHtml(url)}</td>
            <td id="status-${index}">${isLocal ? '<span class="label label-success">待删除</span>' : '<span class="label label-default">外站</span>'}</td>
        `;
        tbody.appendChild(tr);
    });
}

// HTML 转义
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 全选/取消/反选
function selectAll() {
    document.querySelectorAll('.url-checkbox:not(:disabled)').forEach(cb => cb.checked = true);
}

function selectNone() {
    document.querySelectorAll('.url-checkbox').forEach(cb => cb.checked = false);
}

function selectReverse() {
    document.querySelectorAll('.url-checkbox:not(:disabled)').forEach(cb => cb.checked = !cb.checked);
}

function toggleAll(el) {
    document.querySelectorAll('.url-checkbox:not(:disabled)').forEach(cb => cb.checked = el.checked);
}

// 获取选中的 URL（返回相对路径）
function getSelectedUrls() {
    const selected = [];
    document.querySelectorAll('.url-checkbox:checked').forEach(cb => {
        const url = parsedUrls[cb.dataset.index];
        // 转换为相对路径
        let relativePath = url;
        if (url.includes(siteDomain)) {
            relativePath = url.replace(siteDomain, '');
        }
        // 确保以 / 开头
        if (!relativePath.startsWith('/')) {
            relativePath = '/' + relativePath;
        }
        selected.push({index: cb.dataset.index, url: relativePath});
    });
    return selected;
}

// 添加日志
function addLog(message) {
    const logDiv = document.getElementById('deleteLog');
    const logContent = document.getElementById('logContent');
    logDiv.style.display = 'block';
    const time = new Date().toLocaleTimeString();
    logContent.textContent += `[${time}] ${message}\n`;
    logContent.scrollTop = logContent.scrollHeight;
}

// 批量删除
function batchDelete() {
    const selected = getSelectedUrls();
    if (selected.length === 0) {
        new $.zui.Messager("请先选择要删除的图片！", {type: "warning", icon: "exclamation-sign"}).show();
        return;
    }

    bootbox.confirm({
        message: `确认要<strong class="text-danger">永久删除</strong>选中的 <strong>${selected.length}</strong> 张图片吗？<br><small class="text-muted">此操作不可恢复！</small>`,
        buttons: {
            confirm: {label: '确认删除', className: 'btn-danger'},
            cancel: {label: '取消', className: 'btn-default'}
        },
        callback: function(result) {
            if (result) {
                executeDelete(selected, 'delete');
            }
        }
    });
}

// 批量回收
function batchRecycle() {
    const selected = getSelectedUrls();
    if (selected.length === 0) {
        new $.zui.Messager("请先选择要回收的图片！", {type: "warning", icon: "exclamation-sign"}).show();
        return;
    }

    bootbox.confirm({
        message: `确认要将选中的 <strong>${selected.length}</strong> 张图片移至回收站吗？`,
        buttons: {
            confirm: {label: '确认回收', className: 'btn-warning'},
            cancel: {label: '取消', className: 'btn-default'}
        },
        callback: function(result) {
            if (result) {
                executeDelete(selected, 'recycle');
            }
        }
    });
}

// 执行删除/回收
async function executeDelete(selected, mode) {
    addLog(`开始${mode === 'delete' ? '删除' : '回收'} ${selected.length} 张图片...`);
    
    let successCount = 0;
    let failCount = 0;

    for (const item of selected) {
        const statusEl = document.getElementById(`status-${item.index}`);
        statusEl.innerHTML = '<span class="label label-info">处理中...</span>';
        
        try {
            const response = await fetch('/app/del.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `url=${encodeURIComponent(item.url)}&mode=${mode}`
            });
            
            const data = await response.json();
            
            if (data.code === 200) {
                successCount++;
                statusEl.innerHTML = '<span class="label label-success">已' + (mode === 'delete' ? '删除' : '回收') + '</span>';
                addLog(`✓ ${item.url}`);
            } else {
                failCount++;
                statusEl.innerHTML = `<span class="label label-danger">${data.msg || '失败'}</span>`;
                addLog(`✗ ${item.url} - ${data.msg || '失败'}`);
            }
        } catch (e) {
            failCount++;
            statusEl.innerHTML = '<span class="label label-danger">错误</span>';
            addLog(`✗ ${item.url} - 网络错误`);
        }
    }

    addLog(`完成！成功: ${successCount}, 失败: ${failCount}`);
    new $.zui.Messager(`操作完成！成功: ${successCount}, 失败: ${failCount}`, {
        type: failCount === 0 ? "success" : "warning",
        icon: failCount === 0 ? "ok-sign" : "exclamation-sign"
    }).show();
}

// 清空
function clearAll() {
    document.getElementById('inputLinks').value = '';
    document.getElementById('parseResult').style.display = 'none';
    document.getElementById('deleteLog').style.display = 'none';
    document.getElementById('logContent').textContent = '';
    parsedUrls = [];
}
</script>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

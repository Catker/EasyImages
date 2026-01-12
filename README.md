> [!IMPORTANT]
> **本仓库为个人自用版本**
> 
> 本 Fork 针对 `/i` 目录挂载 NFS 的运行环境进行了专项优化，并新增/重构了部分功能：
> 
> **🚀 NFS 优化**
> - 广场页面缓存机制（支持 Redis / 文件缓存 / 关闭）
> - 缓存预热脚本，减少 NFS 慢速扫描
> - 文件计数与列表缓存，提升响应速度
> 
> **✨ 新增特性**
> - 广场页面样式重构，支持图片多选操作
> - 广场分页功能，可配置每页显示数量
> - 智能日期范围显示（今天/昨天/N天前）
> 
> **🔧 功能重构**
> - 统计页面完全重构，支持异步加载
> - 统计数据后台缓存，避免大目录超时
> 
> 如寻求通用版本，请访问上游仓库：[icret/EasyImages2.0](https://github.com/icret/EasyImages2.0)

---

## EasyImage2.0 简单图床

[![EasyImage2.0 GitHub's stars](https://img.shields.io/github/stars/icret/easyImage2.0?style=social)](https://github.com/icret/EasyImages2.0/stargazers)
[![EasyImage2.0 GitHub's forks](https://img.shields.io/github/forks/icret/easyimage2.0?style=social)](https://github.com/icret/EasyImages2.0/network/members)
[![PHP](https://img.shields.io/badge/php-5.6%20--%208.0-blue.svg)](http://php.net)
[![Release](https://img.shields.io/github/v/release/icret/EasyImages2.0)](https://github.com/icret/EasyImages2.0/releases)
[![jsdelivr](https://data.jsdelivr.com/v1/package/gh/icret/EasyImages2.0/badge)](https://cdn.jsdelivr.net/gh/icret/EasyImages2.0@EasyImage2.0/)
[![License](https://img.shields.io/badge/license-GPL_V2.0-yellowgreen.svg)](https://github.com/icret/EasyImages2.0/blob/master/LICENSE)
[![QQ group](https://pub.idqqimg.com/wpa/images/group.png)](https://jq.qq.com/?_wv=1027&k=jfXRHU8Y)

[演示](https://png.cm/) · [手册](https://icret.github.io/EasyImages2.0/#/) · [社区](https://github.com/icret/EasyImages2.0/discussions) · [Telegram](https://t.me/Easy_Image) - 插件: [Chrome](/docs/Chrome插件.md) · [Edge](/docs/Edge插件.md) · [PicGo](/docs/使用PicGo上传.md) · [ShareX](/docs/使用ShareX上传.md) · [Docker](/docs/三方安装指南.md)

目录: [安装](/docs/安装图床.md) | [安全](/docs/安全配置.md) | [API](/docs/API.md) | [鉴黄](/docs/鉴黄.md) | [升级](/docs/图床更新升级.md) | [常见问题](/docs/常见问题.md) | [环境/兼容](#环境要求) | [更新日志](/docs/update.md) | [打赏开发者](/docs/打赏开发者.md) | [鸣谢](#鸣谢) | [许可证](#开源许可) 

> 始于2018年7月，支持多文件上传,简单无数据库,返回图片url,markdown,bbscode,html的一款图床程序
演示地址：[https://png.cm/](https://png.cm/) 
之前一直用的图床程序是:[PHP多图长传程序2.4.3](https://www.jb51.net/codes/40544.html)
由于版本过老并且使用falsh上传，在当前html5流行大势所趋下，遂利用基础知识新写了一个以html5为默认上传并且支持flash,向下兼容至IE9。
***本程序环境要求极低，适用于单一场景（游客上传）和个人使用，不适于多用户复杂场景***
>本人善写bug 发现bug可提交 [issues](https://github.com/icret/EasyImages2.0/issues) 追求稳定请下载 [稳定版](https://github.com/icret/EasyImages2.0/releases)

## 特点

* [x] 支持API
* [x] 支持仅登录后上传
* [x] 支持设置图片质量
* [x] 支持压缩图片大小
* [x] 支持文字/图片水印
* [x] 支持设置图片指定宽/高
* [x] 支持上传图片转换为指定格式
* [x] 支持限制最低宽度/高度上传
* [x] 支持上传其他文件格式
* [x] 在线管理图片
* [x] 支持网站统计
* [x] 支持设置广告
* [x] 支持图片鉴黄
* [x] 支持自定义代码
* [x] 支持上传IP黑白名单
* [x] 支持上传日志IP定位
* [x] 支持限制日上传次数
* [x] 支持创建仅上传用户
* [x] 对于安装环境要求极低
* [x] 对于服务器性能要求极低
* [x] 理论上[支持所有常见格式](/docs/其他格式.md)
* [x] 更多功能支持请安装尝试···

 ## 界面演示
 
 ![简单图床 - 上传界面](./docs/images/README/674074848.png)
 ![简单图床 - 广场界面](./docs/images/README/3053540273.png)
 ![简单图床 - 后台界面](./docs/images/README/2657944724.png)
 ![简单图床 - 统计界面](./docs/images/README/1305032567.png)
 ![简单图床 - 图片信息](./docs/images/README/info.png)
 ![简单图床 - 上传日志](./docs/images/README/log.png)

## 环境要求
> 推荐环境：Nginx + PHP≥7.0 + linux

- ##### 兼容

 >最低`PHP 5.6`,推荐`PHP≥7.0`及以上版本，需要PHP支持`Fileinfo,iconv,zip,mbstring,openssl`扩展,如果缺失会导致无法上传/删除图片
 文件上传视图提供文件列表管理和文件批量上传功能，允许拖拽（需要`HTML5`支持）来添加上传文件，支持上传大图片，优先使用`HTML5`旧得浏览器自动使用`Flash和Silverlight`的方式兼容

## 鸣谢
 
- [verot](https://github.com/verot/class.upload.php "verot" )
- [ZUI](https://github.com/easysoft/zui/tree/zui1 "ZUI" )
  
## 开源许可

 - [GPL-2.0](https://github.com/icret/EasyImages2.0/blob/master/LICENSE) 
 - Copyright © 2018 EasyImage Developer By [Icret](https://github.com/icret)
 
* have fun!

[![Stargazers over time](https://starchart.cc/icret/EasyImages2.0.svg)](https://github.com/icret/EasyImages2.0/stargazers)
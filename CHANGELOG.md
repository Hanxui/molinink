# 更新日志

格式参照 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)；版本号「主.次.小」三位，每次改动必升版本。

## [0.2.0] - 2026-10-25

### 新增

- **媒体转码口 `media/upload/replace`**（filter，`since 0.2`）：上传校验通过、还没命名落盘的这一格里，插件可以换一份字节（转 WebP、压一压）。内核把插件给回来的东西当**不可信输入**复核 —— 扩展名必须是内核认识的图片格式、`finfo` 读出的真 MIME 要对得上、`getimagesize` 解得开、像素与字节都不越界；任何一步不对就当没换，原图照旧落盘，原因写进日志。**插件坏了不该让上传坏掉。**
  - 配套 `UploadPolicy::MAX_PIXELS` 从 `private` 改 `public`：复核要用同一把尺子。
  - 衍生尺寸的编码质量跟着转码口给的值走，不然会出现「设了 75、缩略图还是 80」。
  - 载荷给法两种：`bytes`（直接给内容）或 `path`（给已落盘的临时件，复制过来、不动原件）。
- 「朋友圈」式 CPT 也挂评论区：评论区判断从「`type` 必须是 `post`」放宽到 `post` / `moments`。

### 修复

- 临时目录与站点不在同一卷时（EXDEV、Windows 跨盘）`rename` 落盘失败：退回复制再删，语义不变。转码口换回来的那份文件也走这条路。

### 说明

- 版本号跳到 `0.2.0`：`media/upload/replace` 是新增的对外 API 面，按次版本号进位。

## [0.1.12] - 2026-10-25

### 删除

- **挂件（Widgets）子系统整块移除**，共约 1000 行：
  - 内核 `core/Widget/`（`Builtin` 268 + `Registry` 71 + `Layout` 44）、`core/Admin/WidgetController.php`（456）、`admin/templates/widgets.twig`（134）一并删除，`core/Widget/` 目录不留。
  - 后台：`pages.php` 的 `widgets` 页、`AdminController` 的 `$widgetScreen` 与方法、`Bootstrap` 的控制器绑定与注册表实例化、`appearance-nav.twig` 里「挂件」那一栏（外观组二级导航由四项变三项）。
  - 前台：`Renderer` 的 `widget_area()` Twig 函数、`widgetArea() / widgetRows() / postProvider()` 与注入的 `$postLookup`、模板全局变量 `widgets_configured`；`helpers.php` 的 `register_widget()`；`FrontContext` 里的配套注入。
  - 默认主题：`footer.twig` 的页脚挂件区、`theme-style.twig` 的 `.footer-widgets` 样式、`theme.json` 的 `"widgets": ["footer"]` 声明。
  - 后台样式与脚本：`.page-widgets` / `.savebar-widgets` 全段 CSS（约 5.8 KB），`admin.js` 里 212 行的拖动排序脚本；中英文翻译里 4 条挂件专用文案。
  - 起因：默认主题只声明了一个页脚位，「侧边栏」区永远不渲染 —— 界面上有一半是排了看不见的；内置五种（目录 / 最新评论 / 分类 / 标签云 / 搜索框）也都是几行模板能出的东西。页脚要放什么，用主题设置的文本字段即可。
  - 迁移：老站的 option `theme_widgets` 留着不动（不再被读），前台不会因此报错；`theme/sidebar/collect` 这条 filter 名保留在 `Hook\Registry` 的「尚未接线」表里留档。
- `Kernel::VERSION` → `0.1.12`。

## [0.1.11] - 2026-10-25

### 改进

- 主题设置页的右栏「实时预览」整块撤掉：字段区改成通栏，`theme-settings.twig` 里的预览 `aside`（iframe + 骨架 + 三条说明）、`admin.css` 里 `.pvw / .frame / .pv-lock / .skel` 一套样式与手机端隐藏规则、`admin.js` 里那 149 行的注入脚本（读 `data-pvars`、改 iframe 的 CSS 变量、按 1280×960 等比缩放）、`AppearanceController::previewVars()` 与两个模板变量一并删除。
  - 起因：它要靠主题在 `theme.json` 里维护 `preview_vars` 才有点用，实际两只主题都没声明，框里永远是「保存前的样子」；每次改字段注变量只是制造「看起来已经生效」的错觉。
  - 想看效果：保存后开前台，或在「外观」里点该主题的「预览」（那条 `?molin_theme_preview=` 的整页预览还在，没动）。
- 顺手把 `Kernel::VERSION` 从 `0.1.8` 补到 `0.1.11`：0.1.9 / 0.1.10 出包时忘了改这行，后台页脚显示的版本号一直是旧的。

## [0.1.10] - 2026-10-25

### 改进

- 头像默认来源从 **Gravatar 改成本地首字母**（`local`：内联 SVG data URI，零外部请求）。Gravatar 在国内常年连不上或要等好几秒，而头像是每页都要出的图，挂在外部域名上等于给首屏塞一个不可控的等待 —— 有站点因此整页明显变慢。
- 想继续用 Gravatar 的站不受影响：资料页「头像来源」选 Gravatar 就行。四档（`local` / `upload` / `gravatar` / `custom`）一个没删，只是默认档换了；脏值与非法值也一并回落到 `local`。
- 资料页头像来源下拉：「本地首字母头像（默认，零外部请求）」挪到第一项，Gravatar 那项补注「需能连上 gravatar.com」；「自定义头像源」留空时的回落说明由 Gravatar 改成本地首字母。

## [0.1.9] - 2026-10-25

### 修复

- 装了发布包、走完向导后点「进入后台」得到 `403 Forbidden`（nginx）：站点根下确实有一个 `admin/` 目录（只放后台的 css/js/模板，没有 index 文件），文档给的伪静态只有 `location / { try_files $uri $uri/ ... }`，nginx 把 `/admin` 当目录索引处理就 403 了。伪静态片段补上 `location = /admin`（交给入口）与 `location = /admin/`（301 回 `/admin`）两段。
- 同步修掉这段配置的所有副本：`install/安装说明.txt` 3.1、`install/服务器配置.txt` 一、README（中/英）第 3 步、安装向导最后一屏那段带复制按钮的文本（`install/templates/done.twig`）、安装向导环境页的「伪静态配置示例」（`install/templates/env.twig`），并各自写明两种症状的差别 —— 整段不加是 `/admin` 与文章页 **404**，只加第一段是进后台 **403**。

## [0.1.8] - 2026-10-25

### 修复

- 从仓库下源码包（Code → Download ZIP）打开站点时，不再抛 `require(.../vendor/autoload.php): Failed to open stream` 的裸 Fatal：`index.php` 在拿 autoload 之前先判断文件在不在，缺了就回一页中文说明 —— 换 Releases 里自带 `vendor/` 的包，或在站点根 `composer install --no-dev`。样式与「站点还没装好」页同一套内联样式（此时 Twig 与任何外链都用不上）。
### 改进

- README（中/英）顶部加下载入口与「包从哪下」提示：用 Releases 里的 `molin-x.y.z.zip`；Code → Download ZIP 是源码包，不含 `vendor/` 且必然多套一层 `molinink-main/` 目录。
- README 与 `install/安装说明.txt` 补一条宝塔踩坑：解压多出一层目录时要**拍平**，别改用面板「运行目录」指到子目录 —— `/uploads/` 别名与 `/storage/` 拦截都按站点根写，运行目录一偏移就一起失效（图片 404、备份又能被直接下载）。
- `install/安装说明.txt` 新增「第零步：安装包从哪下」，故障对照表补 `vendor` 缺失一条；源码跑法统一成 `composer install --no-dev`。

## [0.1.7] - 2026-10-25

### 改进

- 安装向导最后一屏直接列出两段服务器配置（伪静态 / 挡住 `/storage/`），各带一个复制按钮，不必再翻安装目录里的文本文件。
- 完成页写明「图片不用配」——上传的图片由程序自己发出去。
## [0.1.6] - 2026-10-24

### 改进

- 安装说明重写为照着点的分步版（以宝塔为例）：建站点与数据库 → 上传解压 → 粘两段配置（伪静态 + 挡住 `/storage/`）→ 走向导 → 四条地址自查 → 常见报错对照。
- 伪静态规则直接写进安装步骤与 README，不再让读者去翻另一个文件。
## [0.1.5] - 2026-10-24

### 修复

- 安装文档漏了一条必需配置：单入口站点要先加伪静态（`try_files`），否则后台 `/admin` 与所有文章页在 nginx 上是 404。已提到文档第一条并标明必加。

### 改进

- 安装说明第四步改为「在面板里粘两段配置」，并补了四条自查地址（首页 / 后台 / `/storage/` / `/uploads/`）。
## [0.1.4] - 2026-10-24

### 新增

- 上传文件直发路由：服务器侧不配 `/uploads/` 别名也能出图；配了则由 nginx 直接发，更快。

### 改进

- 装完只需要加一条服务器规则（挡住 `/storage/`）；`/uploads/` 与 `/themes/` 两条降为可选的性能项。

## [0.1.3] - 2026-10-24

### 修复

- `::ffff:127.0.0.1` 这类 v4 映射的 v6 地址在 Linux 上会被当成公网地址放行（导入插件抓图时的地址闸门漏判）。
- 首页缓存命中判定在读文件时间前没清 stat 缓存，同一请求里刚写下的整页会被误判成命中。
- 一处只有 PHP 8.4 才允许的写法，在 PHP 8.2 / 8.3 上是语法错误。

## [0.1.2] - 2026-10-24

### 修复

- 「说说」等公开内容类型也能点赞点踩（此前只有文章能点）。
- 列表里的作者信息补上主页链接，头像与昵称可点进作者归档。

### 改进

- 安装包里的 `composer.json` 不再携带开发依赖与测试脚本。
- 安装说明的 PHP 扩展清单与实际检查口径对齐。

## [0.1.1] - 2026-10-23

首个对外测试版：内核、后台、默认主题与多语言的第一版，装好即可发文。

### 新增

- 钩子总线、插件加载与能力校验、主题机制与 Twig 受限子集。
- 后台：文章 / 页面 / 分类 / 标签 / 媒体 / 评论 / 用户与角色 / 外观 / 工具 / 站点健康 / 程序更新。
- 安装向导：环境自检 → 数据库 → 站点与管理员 → 写入，失败整体回滚。
- 多语言、SEO（sitemap / robots / feed）、备份与恢复。
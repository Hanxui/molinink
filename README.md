<p align="center"><img src=".github/assets/logo.png" alt="墨林 MolinInk" width="320"></p>

<p align="center">墨林（MolinInk）是一个用 PHP 写的博客程序。装好就能发文，其余交给插件和主题。</p>

<p align="center"><sub>PHP 8.2+ · MySQL 8 · 单入口 · AGPL-3.0</sub></p>

<p align="center"><a href="https://github.com/Hanxui/molinink/releases/latest"><strong>下载安装包</strong></a> · <a href="https://www.molinink.com">官网</a> · <a href="https://demo.molinink.com">演示站</a></p>

<p align="center"><img src=".github/assets/screen-home.png" alt="首页"></p>

## 动手之前，有三件事容易踩

**一、包别下错。** 能直接装的是 Releases 里的 `molin-x.y.z.zip`，它带着 `vendor/`，解压到站点根就能用。

仓库页那个绿色的 `Code → Download ZIP` 下的是源码：仓库里不放 `vendor/`（依赖交给 Composer 管），所以打开只会看到一行 `require(.../vendor/autoload.php): Failed to open stream`，而且还会多套一层 `molinink-main/` 目录。真要从源码跑也行，在站点根执行一次 `composer install --no-dev`，程序认出 vendor 后会自己往下走。

**二、这里的版本不一定是最新的。** 仓库是开发过程中同步过来的快照，我常常先在自己机器上写、改、试，过一阵才推上来。**请以官网上的实际发布版本为准：<https://www.molinink.com>**。两边对不上时，信官网。

**三、有问题先来群里说。** QQ 群 **1109527088**。目前反馈就这一条路（Issues 也能提，但我看得不勤，后面会换成更顺手的方式）。安全漏洞别公开贴，按 [SECURITY.md](SECURITY.md) 走私密渠道。

## 装起来

下面按宝塔面板写，其他面板大同小异。顺利的话十分钟以内。想先看看装好是什么样：<https://demo.molinink.com>

**需要**：PHP 8.2+（`pdo_mysql`、`mbstring`、`json`、`openssl`、`fileinfo`；建议再加上 `gd`、`zip`、`intl`）、MySQL 8 或 MariaDB 10.5+、一个已经解析到这台服务器的域名。

### 1. 建站点和数据库

宝塔 → 网站 → 添加站点：填域名，PHP 版本选 **8.2 或更高**，数据库选 MySQL。建完会给你一套库名、用户名、密码，第 3 步要用，先记下来。

### 2. 上传并解压

网站 → 点站点名 → 文件 → 进站点根目录（一般是 `/www/wwwroot/你的域名`）→ 上传 Releases 里的 `molin-<版本>.zip` → 右键解压到当前目录。

解压完，根目录下要**直接**能看到 `index.php`、`core/`、`install/`。如果多出一层 `molin-x.y.z/`，进去全选、剪切到根目录。

有一点要留意：别用面板的「运行目录」指到那层子目录。`/uploads/`、`/storage/` 这些规则都是按站点根写的，指偏了就跟着失效。

### 3. 粘两段配置

先说伪静态（网站 → 设置 → **伪静态**）。程序只有一个入口，磁盘上并没有 `/admin` 这个文件，得让 nginx 把找不到的地址交给 `index.php`：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

# 后台是程序路由：admin/ 目录里只有静态资源（css/js/模板），没有 index 文件。
# 少了这两行，nginx 会把它当目录索引，点「进入后台」直接 403 Forbidden。
location = /admin {
    try_files $uri /index.php$is_args$args;
}
location = /admin/ {
    return 301 /admin;
}
```

没加的表现是：首页正常，但 `/admin` 和所有文章页都 404。如果只加了第一段、漏掉 `/admin` 那两行，症状会换一种：文章页好了，登后台时 403。

再说 `storage/`（网站 → 设置 → **配置文件**，粘进 `server { }` 里）。里面是备份包、日志、远端备份凭据，不挡住就能被人直接下载：

```nginx
location ^~ /storage/ {
    return 404;
}
```

图片不用另配，程序自己会发。想让 nginx 直接吐图（`/uploads/`、`/themes/`）可以加速，片段在 `install/服务器配置.txt` 里。

### 4. 走安装向导

浏览器打开你的域名，点「开始安装」：环境自检 → 填第 1 步记下的数据库三样 → 站点名称、站点地址、管理员账号 → 写入。中途失败会整体回滚，不会留下装了一半的站。装完记得把 `install/` 删掉。

### 5. 回头看四个地址

| 打开 | 应该是 |
|---|---|
| `你的域名/` | 首页能开 |
| `你的域名/admin` | 后台登录页（404 = 伪静态没生效） |
| `你的域名/storage/backups/` | 404 |
| `你的域名/uploads/某张图片` | 能显示 |

带报错对照表的详细版，在压缩包的 `install/安装说明.txt` 里。

### 不想下包，从源码跑

```bash
composer install --no-dev
cp config.example.php config.php   # 填上数据库
php -S 127.0.0.1:8000              # 或者交给 nginx / apache
```

## 它是怎么搭的

一句话：内核是插座，契约是插头规格。

- 内核不写业务。`core/` 里只有钩子总线、插件加载、主题渲染、路由、缓存、迁移这些东西。
- 钩子名是 `域/对象/阶段`（`post/save/before`），能力名是 `域.动作`（`post.create`）。两套命名空间互不干扰，规则只增不改。
- 建表只有一条路：`core/Migrations/`。没有 `schema.sql`，首次安装就是从空库跑一遍迁移，跟升级走同一条链。
- 模板是 Twig 的受限子集，默认自动转义，模板里不写业务逻辑。
- 框架件都用现成的：fast-route、Twig、Monolog、symfony/mailer、symfony/console。没有 ORM，也没有整套框架。

## 后台长相

<table>
<tr>
<td width="50%"><img src=".github/assets/screen-admin.png" alt="仪表盘"></td>
<td width="50%"><img src=".github/assets/screen-editor.png" alt="编辑器"></td>
</tr>
</table>

## 许可

代码用 **AGPL-3.0-or-later**，全文见 [LICENSE](LICENSE)。

- 免费用、随便改，想拿它做收费服务也可以。
- 改了之后如果当作网站对外提供服务，改动的那部分要开源。
- 分发时把 LICENSE 一起带上。

用到的第三方组件，各自的许可写在 [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)。

「墨林」「MolinInk」这两个名字和 logo 是商标，不跟着代码一起授权。拿它搭自己的站没问题，页脚署名也能改；但别用同一个名字和标识出去发自己的版本。

---

[English](README.en.md) · QQ 群 **1109527088** · 安全问题见 [SECURITY.md](SECURITY.md)

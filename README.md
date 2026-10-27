<p align="center"><img src=".github/assets/logo.png" alt="墨林 MolinInk" width="320"></p>

<p align="center">用 PHP 写的博客程序，装好就能发文。</p>

<p align="center"><sub>PHP 8.2+ · MySQL 8 · 单入口 · AGPL-3.0</sub></p>

<p align="center"><a href="https://github.com/Hanxui/molinink/releases/latest">下载安装包</a> · <a href="https://www.molinink.com">官网</a> · <a href="https://demo.molinink.com">演示站</a></p>

<p align="center"><img src=".github/assets/screen-home.png" alt="首页"></p>

墨林（MolinInk）是一个用 PHP 写的博客程序，跑在 Nginx 加 MySQL 上。装好之后就能写文章、管评论、换主题。程序本体只负责这些基础的结构，其他功能都做成插件，需要什么装什么，默认只带一个主题和一个发邮件用的插件。

## 下载哪个

能直接装的是 Releases 里的包，文件名是 `molin-版本号.zip`。依赖已经一起打进去了（`vendor/` 目录），解压到站点根目录就能用。

仓库页面那个绿色的 `Code` 按钮下的是源码，里面没有 `vendor/`，因为依赖是 Composer 装的，不往仓库里放。这样下下来直接打开，只会看到一行报错：

```
require(.../vendor/autoload.php): Failed to open stream
```

真要从源码跑也行，在站点根目录执行一次 `composer install --no-dev` 把依赖补上，后面的步骤跟下面一样。

另外说一句版本的事。仓库里的东西不一定是最新的，我平时在自己机器上改，隔一段时间才推上来，官网（<https://www.molinink.com>）上发布的才是实际发出去的版本。两边对不上的时候以官网为准。

## 装起来

下面按宝塔面板写，用别的面板也差不多，顺利的话十分钟以内。想先看看装好是什么样子，可以打开 <https://demo.molinink.com>。

需要 PHP 8.2 或更高，扩展要有 `pdo_mysql`、`mbstring`、`json`、`openssl`、`fileinfo`，有 `gd`、`zip`、`intl` 更好。数据库用 MySQL 8 或者 MariaDB 10.5 以上。域名要先解析到这台服务器。

**建站点和数据库。** 在宝塔的「网站」里添加站点，填好域名，PHP 版本选 8.2 以上，数据库选 MySQL。建完面板会给一套库名、用户名和密码，下一步要用，先记下来。

**上传解压。** 点进站点打开文件管理，进站点根目录（一般是 `/www/wwwroot/你的域名`），把从 Releases 下的压缩包传上去，右键解压到当前目录。

解压完，根目录下应该直接能看见 `index.php`、`core/`、`install/` 这三样。要是多套了一层文件夹，进去全选剪切上来。

这里有个容易踩的地方：别用面板的「运行目录」指到那一层子目录去。程序里 `/uploads/`、`/storage/` 这些规则都是按站点根写的，指偏了就不生效。

**配伪静态。** 程序只有一个入口文件，磁盘上并没有 `/admin` 这个文件，所以要让 nginx 把找不到的地址交给 `index.php`，不然文章页和后台都打不开。填到「设置 → 伪静态」里：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

# 后台是程序路由，admin/ 目录里只有 css、js 和模板，没有 index 文件。
# 少了这两行，nginx 会把它当目录，点「进入后台」是 403。
location = /admin {
    try_files $uri /index.php$is_args$args;
}
location = /admin/ {
    return 301 /admin;
}
```

只加第一段、漏掉 `/admin` 那两行的话，首页和文章页都正常，但登录后台是 403。

**挡住 storage。** `storage/` 里放的是备份包、日志和远端备份用的凭据，得挡住，不然能被人直接下载。在「设置 → 配置文件」里，往 `server { }` 中间加：

```nginx
location ^~ /storage/ {
    return 404;
}
```

图片不用管，程序自己会输出。想让 nginx 直接发图片会快一点，片段在压缩包的 `install/服务器配置.txt` 里。

**走向导。** 浏览器打开你的域名，点开始安装。先是环境自检，然后填数据库那三样，再填站点名称、站点地址和管理员账号，最后写入。中途出错会整体回滚，不会留下装了一半的站。装完把 `install/` 删掉。

**核对四个地址。** 装完挨个打开看看：

| 地址 | 应该是 |
|---|---|
| `你的域名/` | 首页能开 |
| `你的域名/admin` | 后台登录页（404 说明伪静态没生效） |
| `你的域名/storage/backups/` | 404 |
| `你的域名/uploads/某张图片` | 能显示 |

带报错对照表的详细版在压缩包的 `install/安装说明.txt` 里。

不想下包、直接从源码跑：

```bash
composer install --no-dev
cp config.example.php config.php   # 填上数据库
php -S 127.0.0.1:8000              # 或者交给 nginx / apache
```

## 代码是怎么组织的

内核只做结构，不管长相也不管具体功能。`core/` 里是钩子总线、插件加载、主题渲染、路由、缓存和迁移这些零件。

插件和主题通过契约跟内核打交道。钩子叫 `域/对象/阶段`，比如 `post/save/before`；能力叫 `域.动作`，比如 `post.create`。这两套名字分开发，互不干扰，只往里加、不改。

建表只有一条路，`core/Migrations/`。没有 `schema.sql` 那种建表脚本，第一次安装就是从空库把迁移跑一遍，跟升级走的是同一条链，省得两边对不上。

模板用的是 Twig 的一个受限子集，默认自动转义，模板里只做展示，不写业务逻辑。

路由、模板引擎、日志、发信都用现成的组件：fast-route、Twig、Monolog、symfony/mailer、symfony/console。没有 ORM，也没套一整个框架。

## 后台

<table>
<tr>
<td width="50%"><img src=".github/assets/screen-admin.png" alt="仪表盘"></td>
<td width="50%"><img src=".github/assets/screen-editor.png" alt="编辑器"></td>
</tr>
</table>

## 许可

代码是 AGPL-3.0-or-later，全文在 [LICENSE](LICENSE)。

免费用、随便改，拿它做收费服务也可以。改了之后如果当作网站对外提供服务，改动的那部分要开源；分发的时候把 LICENSE 一起带上。

用到的第三方组件各自的许可写在 [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)。

「墨林」「MolinInk」这两个名字和 logo 是商标，不在这份代码许可里。拿它搭自己的站没问题，页脚署名也能改，只是别用同一个名字和标识出去发自己的版本。

---

[English](README.en.md) · QQ 群 1109527088

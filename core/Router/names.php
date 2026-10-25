<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

/*
 * 内核路由名表 ——《20-内核/23-路由规范》第 4 节。
 *
 * 路由名是 path() 助手的取值依据,与钩子名、能力名一样属于契约,只增不改。
 * 文档里的 `/admin/*`、`/api/v1/*` 通配写成 fast-route 的 `{path:.+}`,含义一致。
 */

return [
    'home'           => ['methods' => ['GET'], 'paths' => ['/']],
    'home.page'      => ['methods' => ['GET'], 'paths' => ['/page/{page:\d+}']],
    // 2026-10-18 增两格(全站文章页,《23》§4 同步):站点原先没有"全部文章"的前台地址,
    // 主题「查看全部」只能指回首页。这里给一条真路由,列表数据仍走 ListSource::home(同一份查询口径)。
    'home.archive'   => ['methods' => ['GET'], 'paths' => ['/blog']],
    'home.archive.page' => ['methods' => ['GET'], 'paths' => ['/blog/page/{page:\d+}']],
    'search'         => ['methods' => ['GET'], 'paths' => ['/search']],
    // 2026-10-14 增一格(列表片段端点):只吐列表项 + 分页,列表翻页 / 切分类不整页刷新。《23》§4 同步。
    // 公开只读(GET、免会话、免 CSRF),与其余前台路由同一口径。
    'list.fragment'  => ['methods' => ['GET'], 'paths' => ['/list/fragment']],
    'post'           => ['methods' => ['GET'], 'paths' => ['/{slug:[^/]+}']],
    'category'       => ['methods' => ['GET'], 'paths' => ['/category/{slug:[^/]+}']],
    // 归档族分页(《88》§4:分页地址一律由 path() 生成,`home.page` 式)。
    // 2026-10-03 前台归档落地时补的四格,只增不改;《23》§4 已同步。
    'category.page'  => ['methods' => ['GET'], 'paths' => ['/category/{slug:[^/]+}/page/{page:\d+}']],
    'tag'            => ['methods' => ['GET'], 'paths' => ['/tag/{slug:[^/]+}']],
    'tag.page'       => ['methods' => ['GET'], 'paths' => ['/tag/{slug:[^/]+}/page/{page:\d+}']],
    'author'         => ['methods' => ['GET'], 'paths' => ['/author/{slug:[^/]+}']],
    'author.page'    => ['methods' => ['GET'], 'paths' => ['/author/{slug:[^/]+}/page/{page:\d+}']],
    'date'           => ['methods' => ['GET'], 'paths' => ['/{year:\d{4}}/{month:\d{2}}']],
    'date.page'      => ['methods' => ['GET'], 'paths' => ['/{year:\d{4}}/{month:\d{2}}/page/{page:\d+}']],
    // 2026-10-04 增一格(附件页落地《117》):附件不进 post 路由,走独立一段前缀
    'attachment'     => ['methods' => ['GET'], 'paths' => ['/attachment/{slug:[^/]+}']],
    // 2026-10-24 增一格：上传文件直发（服务器侧没配 /uploads/ 别名时靠它兜底，图片不至于全破）
    'uploads.file'   => ['methods' => ['GET'], 'paths' => ['/uploads/{path:.*}']],
    'feed'           => ['methods' => ['GET'], 'paths' => ['/feed', '/feed/rss', '/feed/atom']],
    'sitemap'        => ['methods' => ['GET'], 'paths' => ['/sitemap.xml']],
    'robots'         => ['methods' => ['GET'], 'paths' => ['/robots.txt']],
    'comment.submit' => ['methods' => ['POST'], 'paths' => ['/comment']],
    // 2026-10-20 增四格（站内通知）：列表片段只给登录用户，已读/删除走 POST + CSRF
    'notifications.list'   => ['methods' => ['GET'], 'paths' => ['/notifications']],
    'notifications.count'  => ['methods' => ['GET'], 'paths' => ['/notifications/count']],
    'notifications.read'   => ['methods' => ['POST'], 'paths' => ['/notifications/read']],
    'notifications.delete' => ['methods' => ['POST'], 'paths' => ['/notifications/delete']],
    // 2026-10-06 增一格(文章密码保护解锁):口令表单提交到这里,核过口令把文章 id 记进会话
    'post.unlock'    => ['methods' => ['POST'], 'paths' => ['/unlock']],
    'login'          => ['methods' => ['GET', 'POST'], 'paths' => ['/login']],
    'logout'         => ['methods' => ['GET', 'POST'], 'paths' => ['/logout']],
    'register'       => ['methods' => ['GET', 'POST'], 'paths' => ['/register']],
    // 2026-10-01 作者点头补表(《23》§4 同步登记):找回密码与设新密码两屏,基准在《123》
    'lostpassword'   => ['methods' => ['GET', 'POST'], 'paths' => ['/lostpassword']],
    'resetpassword'  => ['methods' => ['GET', 'POST'], 'paths' => ['/resetpassword']],
    // 契约路径只有这一条;`/admin`、`/admin/` 两个别名在 index.php 里挂同一处理器,
    // 不进表 —— path() 取的是表里首条路径,加了别名会让生成的 URL 丢掉参数。
    'admin'          => ['methods' => ['ALL'], 'paths' => ['/admin/{path:.+}']],
    'api'            => ['methods' => ['ALL'], 'paths' => ['/api/v1/{path:.+}']],
];

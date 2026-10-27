<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

/*
 * 后台页面定义表(既有规范十个一级菜单)。
 *
 * 与 core/Router/names.php 同一套做法:路径、所需能力、菜单归属先钉成契约,
 * 处理器一个个补。`capabilities` 的取值必须来自既有规范的能力名,写错会被能力登记表挡下;
 * 文档里的 `post.*` / `media.*` 在这里摊开成具体能力名,任一命中即放行(Authorizer::canAny)。
 * `menu` 是侧栏标题,null 表示这一项不进菜单(别名页)。
 * 顺序即既有规范的菜单顺序;菜单在服务端按能力过滤(藏菜单不算防护,
 * 所以 handle() 进来还会再校验一次)。
 */

return [
    '' => ['title' => '仪表盘', 'capabilities' => ['read'], 'template' => 'dashboard.twig', 'menu' => '仪表盘'],
    'dashboard' => ['title' => '仪表盘', 'capabilities' => ['read'], 'template' => 'dashboard.twig', 'menu' => null],
    'posts' => [
        'title' => '文章',
        'capabilities' => ['post.create', 'post.edit.own', 'post.edit.others', 'post.publish', 'post.delete.own', 'post.delete.others'],
        'template' => 'posts.twig',
        'menu' => '文章',
    ],
    // 分类与标签:属「文章」组(既有规范「全部文章 / 新建 / 分类 / 标签」),紧排在文章之后。
    // 2026-10-11 作者定:给它一个**自己的侧栏项** —— 此前 menu:null,全站又没有链路进来,
    // 只能手敲 /admin/categories,等于"功能做了但找不到"。门控能力名第 6 条的
    // 厘清结论:category.manage(内核已登记)。
    'categories' => ['title' => '分类与标签', 'capabilities' => ['category.manage'], 'template' => 'categories.twig', 'menu' => '分类与标签'],
    'media' => ['title' => '媒体', 'capabilities' => ['media.upload', 'media.delete.own'], 'template' => 'media.twig', 'menu' => '媒体'],
    // 「页面」屏早就实现了:AdminController 把它分发给 PostsController(page 模式,走 /admin/pages/new 那套),
    // 渲染的是 pages.twig。这一格 template 只作登记与模板存在性契约用,所以要对齐真实模板名 ——
    // 此前写的 wip.twig(占位屏)在功能落地后就成了陈旧残留。
    'pages' => ['title' => '页面', 'capabilities' => ['page.manage'], 'template' => 'pages.twig', 'menu' => '页面'],
    'comments' => ['title' => '评论', 'capabilities' => ['comment.moderate'], 'template' => 'comments.twig', 'menu' => '评论'],
    'appearance' => ['title' => '外观', 'capabilities' => ['theme.switch'], 'template' => 'appearance.twig', 'menu' => '外观'],
    'plugins' => ['title' => '插件', 'capabilities' => ['plugin.activate'], 'template' => 'plugins.twig', 'menu' => '插件'],
    'users' => ['title' => '用户', 'capabilities' => ['user.list'], 'template' => 'users.twig', 'menu' => '用户'],
    // 角色能力矩阵:只读屏;菜单归属借「用户」组(menu:null 不进侧栏,侧栏当前项由 handle 借 users 的位)。
    // 门控 user.list:与用户列表同口径 —— 能看用户的人才看得到角色矩阵。
    'roles' => ['title' => '角色能力矩阵', 'capabilities' => ['user.list'], 'template' => 'roles.twig', 'menu' => null],
    // 个人资料:只改自己的事,进页只要 read;菜单归属借「用户」组(menu:null 不进侧栏)
    'profile' => ['title' => '个人资料', 'capabilities' => ['read'], 'template' => 'profile.twig', 'menu' => null],
    // 日志查看器:只读屏;菜单归属借「工具」组(menu:null 不进侧栏)。
    // 读日志没有专属能力名(既有规范只有 log.write、既有规范无)—— 既有规范不自造,先挂工具区同款的 setting.manage。
    'logs' => ['title' => '日志', 'capabilities' => ['setting.manage'], 'template' => 'logs.twig', 'menu' => null],
    // 站点健康:只读体检屏;菜单归属借「工具」组。
    // 读健康同样没有专属能力名(既有规范未列),与日志同口径挂 setting.manage(§11-8 不自造)。
    'health' => ['title' => '站点健康', 'capabilities' => ['setting.manage'], 'template' => 'health.twig', 'menu' => null],
    'tools' => ['title' => '工具', 'capabilities' => ['setting.manage'], 'template' => 'tools.twig', 'menu' => '工具'],
    'settings' => ['title' => '设置', 'capabilities' => ['setting.manage'], 'template' => 'settings.twig', 'menu' => '设置'],
    // 动作路径,不是页面(既有规范「清理缓存」)。handle 只认登记过的第一段,
    // 不登记就会让 /admin/cache/clear 撞在"后台没有这个页面"上;能力照既有规范的 cache.manage。
    'cache' => ['title' => '缓存', 'capabilities' => ['cache.manage'], 'template' => '', 'menu' => null],
    // 修订版本比对:属「文章」组(menu:null 不进侧栏,侧栏当前项由 handle 借 posts 的位)。
    // 门控的 content.write 意图,落到 post.edit.*(content.write 属既有规范插件申报词表,
    // 不在用户权限登记表;2026-10-04 作者定:维持已登记名,不自造新名)。
    'revisions' => ['title' => '修订版本', 'capabilities' => ['post.edit.own', 'post.edit.others'], 'template' => 'revisions.twig', 'menu' => null],
    // 菜单编辑器:属「外观」组(menu:null 不进侧栏)。既有规范无专属能力,
    // 与外观同口径借 theme.switch(「能换主题的人就能改全站导航」)。
    'menus' => ['title' => '菜单编辑器', 'capabilities' => ['theme.switch'], 'template' => 'menus.twig', 'menu' => null],
    // 挂件页已按作者决定**整块删除**(2026-10-27):默认主题只画了一个页脚位,
    // 「侧边栏」区永远不渲染,内置 5 种挂件全是几行模板就能出的东西 —— 界面留着只会让人排了看不到。
    // 导入与导出:属「工具」组(menu:null 不进侧栏)。既有规范的 backup.manage 属
    // 既有规范插件申报词表,不在用户权限登记表 —— 同工具区口径挂 setting.manage(2026-10-04 作者定)。
    'import-export' => ['title' => '导入与导出', 'capabilities' => ['setting.manage'], 'template' => 'import-export.twig', 'menu' => null],
    // 插件市场已按作者决定**暂撤**(2026-10-15):界面与控制器一并摘下,规格与设计留档待重启;
    // 插件目前只走本地上传 zip(plugin.activate 门控),不再另开一屏。
    // 内核更新:属「设置」组(menu:null 不进侧栏,侧栏当前项借 settings 的位)。
    // 既有规范的 setting.read/setting.write 属既有规范插件申报词表,不在用户权限登记表;
    // 既有规范的 update.core 已登记,取它(2026-10-04 作者定:维持已登记名)。
    'update' => ['title' => '程序更新', 'capabilities' => ['update.core'], 'template' => 'update.twig', 'menu' => null],
];

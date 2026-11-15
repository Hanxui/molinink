<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

use Molin\Content\ContentContext;
use Molin\Content\Post;
use Molin\Http\Response;
use Molin\User\User;

/*
 * 钩子签名登记表(钩子规范第 3 节)。契约数据,不是代码。
 *
 * 文件名不叫 registry.php:钩子API设计第 1 节把它与 Registry.php 列在同目录,
 * 而 Windows 大小写不敏感,两个只差大小写的文件无法共存(开发与打包都在 Windows)。
 * 改名的只有这个数据文件,登记表的内容与校验行为不变。
 *
 * 只登记文档把参数与返回值钉死的钩子:哪份文档钉了,哪条才进这张表。
 * 未登记的钩子(候选钩子、插件领域事件)运行时跳过校验,不报错。
 */

return [
    'content/render/before' => [
        'type'      => 'filter',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'html', 'type' => 'string'],
            ['name' => 'context', 'type' => ContentContext::class],
        ],
        'returns'   => 'string',
        'stability' => 'public',
        'doc'       => '正文输出前加工 HTML(钩子规范7.1、内容模型设计7.1)',
    ],
    'content/render/after' => [
        'type'      => 'filter',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'html', 'type' => 'string'],
            ['name' => 'context', 'type' => ContentContext::class],
        ],
        'returns'   => 'string',
        'stability' => 'public',
        'doc'       => '正文输出后追加 HTML(钩子规范7.1、内容模型设计7.1)',
    ],
    // 2026-09-30 按现行设计:保存链四个钩子的参数形状(钩子规范第 3 节同步登记)。
    'content/save/before' => [
        'type'      => 'filter',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'data', 'type' => 'array'],
            ['name' => 'post_id', 'type' => '?int'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        'doc'       => '落库前改写入数据;post_id 为 null 表示新建(内容模型设计第 5 节)',
    ],
    'content/save/after' => [
        'type'      => 'action',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'post', 'type' => Post::class],
            ['name' => 'is_new', 'type' => 'bool'],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        'doc'       => '写完(含 meta 与分类)后触发',
    ],
    'content/delete/before' => [
        'type'      => 'action',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'post', 'type' => Post::class],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        'doc'       => '移入回收站或彻底删除之前触发',
    ],
    'post/status/after' => [
        'type'      => 'action',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'post', 'type' => Post::class],
            ['name' => 'from', 'type' => 'string'],
            ['name' => 'to', 'type' => 'string'],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        'doc'       => '状态跃迁完成后触发(内容模型设计第 5 节状态机)',
    ],
    'user/capability/validate' => [
        'type'      => 'filter',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'allow', 'type' => 'bool'],
            ['name' => 'capability', 'type' => 'string'],
            ['name' => 'user', 'type' => '?' . User::class],
            ['name' => 'target', 'type' => 'mixed'],
        ],
        'returns'   => 'bool',
        'stability' => 'public',
        // 唯一有原文依据的形状:用户权限设计第 10 节的 add_filter(..., fn(bool $allow, string $cap, $user, $obj), 10, 4)。
        // user/target 的具体类文档未定,先按"可空 User + 任意对象"登记,收紧前须复核。
        'doc'       => '授权判定收口,插件可据此收紧或放宽(用户权限设计第 4、10 节)',
    ],
    // 2026-09-30 按现行设计:登录与注册链六个钩子的形状(钩子规范第 3 节同步登记)。
    'user/login/before' => [
        'type'      => 'filter',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'data', 'type' => 'array'],
            ['name' => 'ip', 'type' => 'string'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        // data 至少含 account。钩子规范第 7 节把 before 定成 filter(可改可中止):
        // 改账号归一化,或抛校验异常直接拒这次登录 —— 强制验证码就走这条路。
        'doc'       => '凭据校验前触发,可改写提交内容或抛异常中止(用户权限设计第 7 节)',
    ],
    'user/login/after' => [
        'type'      => 'action',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'user', 'type' => User::class],
            ['name' => 'ip', 'type' => 'string'],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        'doc'       => '登录成功后触发,会话已建',
    ],
    'user/login/failed' => [
        'type'      => 'action',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'username', 'type' => 'string'],
            ['name' => 'ip', 'type' => 'string'],
            ['name' => 'attempts_left', 'type' => 'int'],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        'doc'       => '登录失败后触发;计数与锁定由内核负责(安全基线第 2.2 节),插件只做附加动作',
    ],
    'user/logout/after' => [
        'type'      => 'action',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'user', 'type' => User::class],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        'doc'       => '登出、服务端会话已销毁后触发',
    ],
    'user/register/before' => [
        'type'      => 'filter',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'data', 'type' => 'array'],
            ['name' => 'ip', 'type' => 'string'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        'doc'       => '建号前改注册数据或抛校验异常拒绝(用户权限设计第 5 节:验证码、邀请码挂这里)',
    ],
    'user/register/after' => [
        'type'      => 'action',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'user', 'type' => User::class],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        'doc'       => '账号建好后触发;验证邮件等善后归插件(用户权限设计第 6 节)',
    ],
    // 2026-10-04 增补:发信插件(mail-smtp)落地,内核签发重置令牌后交棒(用户权限设计 ②)
    'user/lostpassword/after' => [
        'type'      => 'action',
        'since'     => '0.49',
        'args'      => [
            ['name' => 'user', 'type' => User::class],
            ['name' => 'link', 'type' => 'string'],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        'doc'       => '重置令牌签发后触发,入参是账号与重置链接;重置邮件等善后归插件(用户权限设计6.3、找回密码界面规格)',
    ],
    // 2026-09-30:媒体库设计第 2、8.2 节把这两个的参数形状写明了,才进这张表。
    // media/upload/after 与 media/render/after 只给了类型和时机、没给参数,仍待确认。
    'media/upload/before' => [
        'type'      => 'filter',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'meta', 'type' => 'array'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        'doc'       => '校验前改白名单与上限:meta.allowed_ext / meta.max_bytes(媒体库设计第 2 节示例)',
    ],
    // 2026-10-25 随图片压缩插件落地(0.2.0):校验过、落盘前那唯一一格,让插件换一份字节。
    'media/upload/replace' => [
        'type'      => 'filter',
        'since'     => '0.2',
        'args'      => [
            ['name' => 'image', 'type' => 'array'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        'doc'       => '校验通过后、落盘前:image.path 为临时文件,插件可原样返回(不换)或给回'
            . 'path|bytes + extension + mime(+ quality)换一份字节;内核复核后落盘,不合格按原图(媒体库设计第 7.1 节)',
    ],
    'media/delete/after' => [
        'type'      => 'action',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'media', 'type' => 'array'],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        'doc'       => '媒体已移入回收站后触发,参数是这条 media 记录(媒体库设计第 8.2 节)',
    ],
    // 2026-10-03:钩子规范7.11 候选 `theme/setting/after` 随主题配置器启用,补 since。
    'theme/setting/after' => [
        'type'      => 'filter',
        'since'     => '0.39',
        'args'      => [
            ['name' => 'values', 'type' => 'array'],
            ['name' => 'slug', 'type' => 'string'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        'doc'       => '主题设置保存后,入参是全量取值与主题名(主题配置器设计第 4 节)',
    ],
    // 2026-10-03 按现行设计:五个冻结钩子的形状(钩子规范第 3 节同步登记,/)。
    'plugin/activate/before' => [
        'type'      => 'action',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'plugin', 'type' => 'string'],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        // 时序:状态已写、psr-4 已注册,插件入口尚未加载 —— 依赖检查类监听在此拦截(抛异常即中止启用)
        'doc'       => '启用时、插件入口加载前触发;入参是插件名(插件清单schema第 9 节校验流程)',
    ],
    'plugin/activate/after' => [
        'type'      => 'action',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'plugin', 'type' => 'string'],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        'doc'       => '插件入口加载完成后触发;入参是插件名',
    ],
    'plugin/deactivate/after' => [
        'type'      => 'action',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'plugin', 'type' => 'string'],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        // 时序:状态与钩子索引都已更新后才触发(2026-10-03 修:原实现早于状态落库触发,与 after 语义不符)
        'doc'       => '停用生效后触发;入参是插件名',
    ],
    'admin/menu/collect' => [
        'type'      => 'filter',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'items', 'type' => 'array'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        // 每项 {title: string, path: string, active?: bool, badge?: string};核心逐条归一,坏形状丢弃
        'doc'       => '侧栏菜单收口,插件增删菜单项(后台信息架构第 4 节:菜单只是体验,页面还要自校能力)',
    ],
    'admin/dashboard/collect' => [
        'type'      => 'filter',
        'since'     => '1.0',
        'args'      => [
            ['name' => 'cards', 'type' => 'array'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        // 每张 {title: string, html: string};html 由插件自产、按可信片段对待(同正文管线的信任级)
        'doc'       => '后台首页卡片收口,插件追加自己的卡片(后台信息架构第 4 节)',
    ],
    // 2026-10-22 新增:定时任务收集与站点输出三件套。形状由内核的消费端钉死,插件只填声明。
    'system/cron/collect' => [
        'type'      => 'filter',
        'since'     => '0.95',
        'args'      => [
            ['name' => 'jobs', 'type' => 'array'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        // 每项 {seconds: int, note: string, run: callable};run 的签名是 fn(int $now, bool $dryRun): int。
        // 内建任务同名优先,插件覆盖不了 —— 见 Cron::jobs()。
        'doc'       => '插件登记定时任务,内建任务同名优先(CLI设计第 5 节)',
    ],
    'site/sitemap/collect' => [
        'type'      => 'filter',
        'since'     => '0.95',
        'args'      => [
            ['name' => 'urls', 'type' => 'array'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        // 每项 {loc: string, lastmod?: string, changefreq?: string, priority?: string};loc 必须是 http(s)
        'doc'       => '站点地图条目收集,渲染前可增删改(路由设计)',
    ],
    'site/feed/collect' => [
        'type'      => 'filter',
        'since'     => '0.95',
        'args'      => [
            ['name' => 'posts', 'type' => 'array'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        // 每项是 Molin\Content\Post;非 Post 的返回值被内核丢弃
        'doc'       => 'RSS / Atom 条目收集(路由设计)',
    ],
    'site/seo/replace' => [
        'type'      => 'filter',
        'since'     => '0.95',
        'args'      => [
            ['name' => 'tags', 'type' => 'array'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        // 每项 {tag: string, attrs: array<string, scalar>, content?: string};content 为 script/style 时按原始片段输出
        'doc'       => 'head 标签整体替换,插件补 canonical / 统计脚本等(路由设计)',
    ],
    'social/services/collect' => [
        'type'      => 'filter',
        'since'     => '0.97',
        'args'      => [
            ['name' => 'services', 'type' => 'array'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        // 形状 {social: ?Molin\Social\SocialRepository, notifications: ?Molin\Notification\NotificationRepository, notifier: ?Molin\Notification\Notifier}
        'doc'       => '社交 / 通知的实现装配:插件往里填自己的实现,内核只留接口与路由(钩子规范7.10.2)',
    ],
    'theme/features/collect' => [
        'type'      => 'filter',
        'since'     => '0.97',
        'args'      => [
            ['name' => 'features', 'type' => 'array'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        // 形状 array<string, bool>;内核只认 social / notifications 两个键
        'doc'       => '主题能力开关:插件停用后,模板据此隐藏对应 UI(主题目录约定)',
    ],
    // 2026-11 新增候选钩子:登录门闸。返回 null 放行,返回 Response 即接管(404 伪装等)。
    'user/login/validate' => [
        'type'      => 'filter',
        'since'     => '0.98',
        'args'      => [
            ['name' => 'response', 'type' => 'null'],
            ['name' => 'ip', 'type' => 'string'],
        ],
        'returns'   => '?' . Response::class,
        'stability' => 'public',
        'doc'       => '后台登录屏准入门闸,未登录访问 /login 前触发;返回 Response 即接管(如伪装 404)',
    ],
    // 2026-11 新增候选钩子:404 接管口。分发后统一在 index.php 触发一次,覆盖路由与控制器两条 404 源。
    'route/not-found/replace' => [
        'type'      => 'filter',
        'since'     => '0.98',
        'args'      => [
            ['name' => 'response', 'type' => 'null'],
            ['name' => 'path', 'type' => 'string'],
        ],
        'returns'   => '?' . Response::class,
        'stability' => 'public',
        'doc'       => '请求以 404 收场时触发;返回 Response 即整体替换(如暗门插件渲染自己的入口页)',
    ],
    'admin/editor/collect' => [
        'type'      => 'filter',
        'since'     => '0.2',
        'args'      => [
            ['name' => 'extensions', 'type' => 'array'],
            ['name' => 'type', 'type' => 'string'],
        ],
        'returns'   => 'array',
        'stability' => 'public',
        // 形状 {assets: list<{type: 'style'|'script', src: string}>, buttons: list<{id, label, title?, svg?}>};
        // src 必须是根相对路径,形状不合的条目由内核丢弃 —— 见编辑器区块设计
        'doc'       => '编辑页扩展收集:插件给出工具条按钮与自己的后台资产(编辑器区块设计)',
    ],
    'admin/editor/render' => [
        'type'      => 'action',
        'since'     => '0.2',
        'args'      => [
            ['name' => 'type', 'type' => 'string'],
        ],
        'returns'   => 'void',
        'stability' => 'public',
        // 输出按可信片段对待:插件面板自带遮罩与焦点管理,内核只负责把它放进对话框层
        'doc'       => '编辑页对话框层渲染时:插件 echo 自己的面板 / 弹层(编辑器区块设计)',
    ],
];

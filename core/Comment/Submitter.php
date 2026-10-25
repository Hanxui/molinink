<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Comment;

use Molin\Content\HtmlSanitizer;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Option\Options;
use Molin\User\Authorizer;
use Molin\User\User;

/**
 * 评论提交流(《31-评论系统规范》§4 的十步 + §5 的最低限度反垃圾)。
 *
 * 边界口诀:内核保证"能用" —— 频率、蜜罐、黑名单、首评必审;智能判定全交插件,
 * 插件挂在 comment/submit/before 这个 filter 上,扔 ValidationException 就能拒掉一条。
 */
final class Submitter
{
    /** 蜜罐字段:页面上不可见,被填了就是机器人 */
    public const HONEYPOT = 'website_hp';

    /** 同 IP 同文章的最小间隔(《31》§5) */
    public const FLOOD_SECONDS = 30;

    /** 同 IP 每小时上限 */
    public const HOURLY_LIMIT = 10;

    /** 提交快于这个秒数判可疑 → 落待审 */
    public const TOO_FAST_SECONDS = 3;

    /** 单条评论超过这么多链接 → 落待审 */
    public const MAX_LINKS = 2;

    public function __construct(
        private readonly CommentRepository $comments,
        private readonly PostRepository $posts,
        private readonly Options $options,
        private readonly Authorizer $can
    ) {
    }

    /**
     * 提一条评论。返回它的最终状态(approved / pending),文案交给调用方。
     *
     * @return array{status:string, id:int}
     */
    public function submit(Request $request, ?User $user): array
    {
        $post = $this->post($request);
        $this->gateOpen($post, $user);

        $data = [
            'post_id' => $post->id,
            'parent_id' => $this->parentId($request),
            'user_id' => $user === null ? 0 : $user->id,
            'author_name' => trim($request->input('author_name')),
            'author_email' => trim($request->input('author_email')),
            'author_url' => $this->url($request->input('author_url')),
            'author_ip' => $request->ip(),
            'author_ua' => mb_substr($request->userAgent(), 0, 255),
            'content' => HtmlSanitizer::clean($request->input('content'), HtmlSanitizer::COMMENT_TAGS),
            'type' => 'comment',
        ];

        // 《31》§4 ②:非空与长度。白名单过滤后的纯文本才算数
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($data['content'])) ?? '');

        if ($plain === '') {
            throw new ValidationException('评论内容不能为空', ['content' => ['写两句再提交']]);
        }

        if (mb_strlen($plain) > 5000) {
            throw new ValidationException('评论太长了', ['content' => ['最多 5000 字']]);
        }

        $this->guestFields($data, $request, $user);
        $this->honeypot($request);

        // 频率闸是给匿名流量兜底的;能审的人(站长/编辑)不受 30 秒/每小时上限拦 —— 与后台行内回复同口径
        if (!$this->isModerator($user)) {
            $this->frequency($data['post_id'], $request->ip());
        }

        // 《31》§4 ⑥:filter,插件可改内容或抛异常拒绝
        $filtered = apply_filters('comment/submit/before', $data, $request->ip());

        if (is_array($filtered)) {
            /** @var array<string, mixed> $filtered */
            $data = $filtered;
        }

        $status = $this->status($data, $request, $user);
        $data['status'] = $status;

        $id = $this->comments->insert($data + ['created_at' => gmdate('Y-m-d H:i:s')]);
        $this->comments->recomputeCount((int) $data['post_id']);

        // 《31》§4 ⑨:入库后(通知、索引交插件)
        do_action('comment/submit/after', $id, (int) $data['post_id']);

        return ['status' => $status, 'id' => $id];
    }

    private function post(Request $request): Post
    {
        $post = $this->posts->find(max(0, (int) $request->input('post_id')));

        if ($post === null || !in_array($post->type, ['post', 'page'], true) || $post->status !== 'publish') {
            throw new ValidationException('找不到这篇文章', ['post_id' => ['文章不存在或未发布']]);
        }

        // 《34》:单篇的 comment_status 是站长逐篇定的,关闭就是不收
        if ($post->comment_status !== 'open') {
            throw new ValidationException('这篇文章已关闭评论', ['post_id' => ['评论已关闭']]);
        }

        return $post;
    }

    private function parentId(Request $request): int
    {
        $parentId = max(0, (int) $request->input('parent_id'));

        if ($parentId === 0) {
            return 0;
        }

        $parent = $this->comments->find($parentId);

        // 楼层不存在、不是同一篇、还没过审 —— 都不许挂
        if ($parent === null || (int) $parent['post_id'] !== max(0, (int) $request->input('post_id')) || $parent['status'] !== 'approved') {
            throw new ValidationException('回复的楼层不存在', ['parent_id' => ['刷新后再试']]);
        }

        return $parentId;
    }

    /**
     * 游客的昵称与邮箱:登录用户免填(身份跟会话走),游客按 comment_require_name_email。
     *
     * @param array<string, mixed> $data
     */
    private function guestFields(array &$data, Request $request, ?User $user): void
    {
        if ($user !== null) {
            $data['author_name'] = $user->display_name;
            $data['author_email'] = $user->email;

            return;
        }

        $required = $this->options->get('comment_require_name_email', '1') === '1';

        if ($data['author_name'] === '' && $required) {
            throw new ValidationException('昵称不能为空', ['author_name' => ['必填']]);
        }

        if ($data['author_email'] !== '' && filter_var($data['author_email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('邮箱格式不对', ['author_email' => ['看起来不像一个邮箱']]);
        }

        if ($data['author_email'] === '' && $required) {
            throw new ValidationException('邮箱不能为空', ['author_email' => ['必填,且前台永不显示']]);
        }
    }

    private function honeypot(Request $request): void
    {
        // 整站关掉蜜罐就不再拦 —— 关它是站长的明示选择,不该偷偷还拦着
        if ($this->options->get('comment_honeypot', '1') === '0') {
            return;
        }

        // 蜜罐被填 = 机器人;不解释,给一句和正常失败一样的话,别教它怎么绕
        if (trim($request->input(self::HONEYPOT)) !== '') {
            throw new ValidationException('评论提交失败', ['content' => ['请稍后再试']]);
        }
    }

    private function frequency(int $postId, string $ip): void
    {
        $seconds = $this->floodSeconds();
        $last = $this->comments->lastAtForIp($postId, $ip);

        if ($seconds > 0 && $last !== null && time() - $last < $seconds) {
            throw new ValidationException('评论太快了', ['content' => ['同一篇歇 ' . $seconds . ' 秒再来']]);
        }

        if ($this->comments->countByIpSince($ip, time() - 3600) >= self::HOURLY_LIMIT) {
            throw new ValidationException('评论太多', ['content' => ['一小时后再来']]);
        }
    }

    /** 同 IP 同文章的最小间隔走选项;缺失或空退回常量默认值(30 秒) */
    private function floodSeconds(): int
    {
        $value = $this->options->get('comment_flood_sec', null);

        if ($value === null || trim((string) $value) === '') {
            return self::FLOOD_SECONDS;
        }

        return max(0, (int) $value);
    }

    /**
     * 全局开关与自动关闭(《31》§6)。两者口径不同:全局关了只挡游客(后台原话"关闭后只有注册用户能留言"),
     * 自动关闭对所有人(含登录读者)生效,只豁免能审的人 —— 与"能审的人不受栏"同口径。
     */
    private function gateOpen(Post $post, ?User $user): void
    {
        if ($user === null && $this->options->get('comment_open', '1') !== '1') {
            throw new ValidationException('评论已关闭', ['content' => ['本站暂时关闭了评论']]);
        }

        if ($this->isModerator($user)) {
            return;
        }

        $days = (int) $this->options->get('comment_auto_close_days', '14');

        if ($days <= 0 || $post->published_at === null || $post->published_at === '') {
            return;
        }

        // 发布时间按 UTC 存(gmdate),补 ' UTC' 才能对到真实时间戳
        $stamp = strtotime($post->published_at . ' UTC');

        if ($stamp !== false && time() - $stamp > $days * 86400) {
            throw new ValidationException('评论已关闭', ['post_id' => ['发表已超过 ' . $days . ' 天']]);
        }
    }

    /**
     * 状态怎么定(《31》§3、§5、§6):能审评论的登录身份直发;默认状态打底,
     * "可疑"一律压回待审;邮箱过审过一次就免审。
     *
     * @param array<string, mixed> $data
     */
    private function status(array $data, Request $request, ?User $user): string
    {
        // 能审的人(站长/编辑)发的评论直发 —— App 行内回复与后台顺手回评同一条路,自己给自己排队没有意义
        if ($this->isModerator($user)) {
            return 'approved';
        }

        // 登录读者免审(comment_registered_approved):注册即信任,排在默认状态判定之前
        if ($user !== null && $this->options->get('comment_registered_approved', '1') === '1') {
            return 'approved';
        }

        $default = $this->options->get('comment_default_status', 'pending') === 'approved' ? 'approved' : 'pending';

        if ($default === 'approved') {
            return 'approved';
        }

        // 首评必审:这个邮箱从未有过已通过的评论;白名单关掉后一律当没见过,首次必进审
        $whitelist = $this->options->get('comment_whitelist', '1') === '1';
        $email = (string) ($data['author_email'] ?? '');
        $seen = $whitelist && $email !== '' && $this->comments->approvedCountForEmail($email) > 0;

        if (!$seen) {
            return 'pending';
        }

        $content = (string) ($data['content'] ?? '');
        $suspicious = substr_count(strtolower($content), 'http') > self::MAX_LINKS;

        // 时间戳启发式只对匿名表单有意义(表单里藏 form_ts);登录身份没有表单时间一说,不拿缺席的字段压审
        if ($user === null) {
            $suspicious = $suspicious
                || (int) $request->input('form_ts') === 0
                || time() - max(0, (int) $request->input('form_ts')) < self::TOO_FAST_SECONDS;
        }

        // 黑名单关键词命中 → 待审(《31》§6 的 comment_moderation_keys)
        $keys = (string) $this->options->get('comment_moderation_keys', '');

        foreach (array_filter(array_map('trim', explode(',', $keys)), static fn (string $k): bool => $k !== '') as $key) {
            if ($key !== '' && str_contains(strtolower($content), strtolower($key))) {
                $suspicious = true;

                break;
            }
        }

        return $suspicious ? 'pending' : 'approved';
    }

    /** 能审评论的登录身份(站长/编辑):直发、不受频率闸 —— 与后台行内回复同口径 */
    private function isModerator(?User $user): bool
    {
        return $user !== null && $this->can->can($user, 'comment.moderate');
    }

    private function url(string $given): string
    {
        $url = trim($given);

        // 网址选填,但给了就必须是 http(s),别的一律当没填 —— 别让它变成跳板
        if ($url === '' || preg_match('#\Ahttps?://[^\s]+\z#i', $url) !== 1) {
            return '';
        }

        return $url;
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Community;

use App\Enums\PostStatus;
use App\Exceptions\SocialException;
use App\Models\Post;
use App\Services\Moderation\Contracts\ModerationChannel;
use App\Support\MediaUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * 社群图片帖（T6）。
 *
 * 发布流程（唯一入口，任何宿主/后台都不能绕过）：
 *   落库(pending) → 机审 → pass 且允许自动发布 → approved；reject → rejected；说不准 → 留在 pending
 *
 * 为什么先落库再机审：机审要留痕（moderation 原始结论），且机审超时/失败时帖子必须有归宿，
 * 否则用户点了发布却什么都没发生。
 */
class PostService
{
    public function __construct(private readonly ModerationChannel $moderation) {}

    /**
     * @param  array{content?: string|null, images?: array<int, string>}  $data
     */
    public function publish(int $userId, array $data): Post
    {
        $content = trim((string) ($data['content'] ?? ''));
        $images = array_values(array_filter(
            (array) ($data['images'] ?? []),
            static fn ($key) => is_string($key) && trim($key) !== ''
        ));

        if ($content === '' && $images === []) {
            throw SocialException::invalid('帖子内容与图片不能同时为空');
        }

        $maxImages = max(1, (int) config('goat.community.post_max_images', 9));

        if (count($images) > $maxImages) {
            throw SocialException::invalid(sprintf('最多上传 %d 张图片', $maxImages));
        }

        $content = mb_substr($content, 0, 1000);
        $result = $this->moderation->screen($content, $images);

        $post = Post::query()->create([
            'user_id' => $userId,
            'content' => $content === '' ? null : $content,
            'images' => $images === [] ? null : $images,
            'status' => PostStatus::Pending,
            'moderation' => $result->toArray(),
        ]);

        if ($result->rejected()) {
            return $post->transitTo(PostStatus::Rejected, [
                'reject_reason' => $result->reason === '' ? '内容未通过机审' : $result->reason,
            ]);
        }

        // 灰度期可以关掉自动发布：机审通过也先人工过一遍（GOAT_COMMUNITY_AUTO_PUBLISH=false）
        if ($result->passed() && (bool) config('goat.community.auto_publish_on_pass', true)) {
            return $post->transitTo(PostStatus::Approved, [
                'published_at' => Carbon::now(),
                'reject_reason' => null,
            ]);
        }

        return $post;
    }

    public function feed(?string $keyword = null, int $perPage = 20): LengthAwarePaginator
    {
        return Post::query()
            ->visible()
            ->with('author')
            ->when($keyword !== null && $keyword !== '', function ($query) use ($keyword) {
                // 转义 LIKE 通配符：用户输入 % 不该变成「全表匹配」
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
                $query->where('content', 'like', '%'.$escaped.'%');
            })
            ->orderByDesc('published_at')
            ->orderByDesc('post_id')
            ->paginate($perPage);
    }

    /** 详情：已发布任何人可看；未过审/待审只有作者自己能看到（用来展示审核状态） */
    public function show(int $postId, ?int $viewerId = null): Post
    {
        $post = Post::query()->with('author')->whereKey($postId)->first();

        if ($post === null) {
            throw SocialException::notFound('帖子不存在');
        }

        if (! $post->isVisible() && (int) $post->user_id !== $viewerId) {
            throw SocialException::notFound('帖子不存在或未通过审核');
        }

        return $post;
    }

    public function withdraw(int $userId, int $postId): Post
    {
        $post = Post::query()->whereKey($postId)->first();

        if ($post === null) {
            throw SocialException::notFound('帖子不存在');
        }

        if ((int) $post->user_id !== $userId) {
            throw SocialException::forbidden('只能撤回自己发布的帖子');
        }

        if ($post->status === PostStatus::Withdrawn) {
            return $post;
        }

        return $post->transitTo(PostStatus::Withdrawn);
    }

    /** 人工审核：只处理 pending，已出结论的帖子不重复审（避免「先拒后放」无痕） */
    public function review(int $postId, string $action, ?string $reason = null): Post
    {
        $post = Post::query()->whereKey($postId)->first();

        if ($post === null) {
            throw SocialException::notFound('帖子不存在');
        }

        if ($post->status !== PostStatus::Pending) {
            throw SocialException::conflict('该帖子已处理（当前：'.$post->status->label().'）');
        }

        if ($action === 'approve') {
            return $post->transitTo(PostStatus::Approved, [
                'published_at' => Carbon::now(),
                'reject_reason' => null,
            ]);
        }

        return $post->transitTo(PostStatus::Rejected, [
            'reject_reason' => $reason === null || trim($reason) === '' ? '人工审核未通过' : mb_substr(trim($reason), 0, 255),
        ]);
    }

    /** 我的帖子：含待审/未通过/已撤回，前端按 status 分 tab */
    public function mine(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return Post::query()
            ->where('user_id', $userId)
            ->orderByDesc('post_id')
            ->paginate($perPage);
    }

    public function pendingPaginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->paginate(PostStatus::Pending->value, $perPage);
    }

    /**
     * 后台帖子队列：默认待审，也可回看已通过/已驳回（申诉与复盘需要）。
     *
     * 只改筛选不改排序：`post_id` 升序 = 先到先审，避免审核顺序随机。
     */
    public function paginate(?string $status, int $perPage = 20): LengthAwarePaginator
    {
        return Post::query()
            ->where('status', $status !== null && $status !== '' ? $status : PostStatus::Pending->value)
            ->orderBy('post_id')
            ->paginate($perPage);
    }

    /** @return array<string, mixed> */
    public function payload(Post $post): array
    {
        $author = $post->author;

        return [
            'post_id' => $post->post_id,
            'content' => $post->content,
            'images' => $post->imageUrls(),
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            // 未通过时给作者看原因；已发布时为 null
            'reject_reason' => $post->status === PostStatus::Rejected ? $post->reject_reason : null,
            'published_at' => $post->published_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
            'author' => [
                'user_id' => (int) $post->user_id,
                'nickname' => $author?->nickname,
                'username' => $author?->username,
                'avatar_url' => MediaUrl::for($author?->avatar_key),
            ],
        ];
    }

    /** 供后台审阅的单条载荷：额外带机审原始结论（复审/申诉时据此复盘） */
    public function adminPayload(Post $post): array
    {
        $payload = $this->payload($post);
        $payload['moderation'] = $post->moderation ?? [];

        return $payload;
    }
}

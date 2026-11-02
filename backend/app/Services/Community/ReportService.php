<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Community;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\ReportTargetType;
use App\Exceptions\SocialException;
use App\Models\Listing;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 举报工单（T6 · 社群治理）。
 *
 * 提交侧的责任是「证明这个目标真的存在、且不是自己」：
 * 不存在的 ID 一律 404（不承认他人可见范围外的内容存在），举报自己 422。
 *
 * 后台侧只做判定，不自动改内容状态：下架/封禁要留操作人和备注，
 * 举报量再大也不能让一条规则替运营背锅。
 */
class ReportService
{
    /**
     * @throws SocialException 目标不存在(404) / 举报自己(422) / 重复待处理(409)
     */
    public function submit(
        int $reporterId,
        ReportTargetType $targetType,
        int $targetId,
        ReportReason $reason,
        ?string $detail = null,
    ): Report {
        $ownerId = $this->ownerOf($targetType, $targetId);

        if ($ownerId === $reporterId) {
            throw SocialException::invalid('不能举报自己发布的内容');
        }

        $detail = $detail === null || trim($detail) === '' ? null : mb_substr(trim($detail), 0, 500);

        return DB::transaction(function () use ($reporterId, $targetType, $targetId, $reason, $detail) {
            $existing = Report::query()
                ->where('reporter_id', $reporterId)
                ->where('target_type', $targetType->value)
                ->where('target_id', $targetId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status === ReportStatus::Pending) {
                    throw SocialException::conflict('已提交过举报，平台正在处理');
                }

                // 判定结束后再次举报：复用原工单并覆盖证据（唯一约束下不新增记录）。
                // 不这么做的话，「举报一次被驳回」就等于给对方内容上了护身符。
                $existing->update([
                    'reason' => $reason,
                    'detail' => $detail,
                    'status' => ReportStatus::Pending,
                    'handled_by' => null,
                    'handled_at' => null,
                    'handle_note' => null,
                ]);

                return $existing->refresh();
            }

            try {
                return Report::query()->create([
                    'reporter_id' => $reporterId,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'reason' => $reason,
                    'detail' => $detail,
                    'status' => ReportStatus::Pending,
                ]);
            } catch (QueryException $e) {
                // 并发重复提交：唯一约束是最后一道闸，直接按冲突返回
                throw SocialException::conflict('已提交过举报，平台正在处理');
            }
        });
    }

    /** 目标归属人：用于「不能举报自己」，同时顺带校验目标存在且可见 */
    private function ownerOf(ReportTargetType $type, int $targetId): int
    {
        switch ($type) {
            case ReportTargetType::Listing:
                $listing = Listing::query()->whereKey($targetId)->first();

                if ($listing === null) {
                    throw SocialException::notFound('商品不存在或已下架');
                }

                return (int) $listing->seller_id;

            case ReportTargetType::Post:
                $post = Post::query()->whereKey($targetId)->first();

                if ($post === null || ! $post->isVisible()) {
                    throw SocialException::notFound('帖子不存在或已下线');
                }

                return (int) $post->user_id;

            case ReportTargetType::Comment:
                $comment = PostComment::query()->whereKey($targetId)->first();

                if ($comment === null || ! $comment->isVisible()) {
                    throw SocialException::notFound('评论不存在或已删除');
                }

                // 帖子被下架时评论也不再公开展示，对举报人同样按「不存在」处理，
                // 避免举报入口变成探测他人私有内容的探针。
                $post = Post::query()->whereKey($comment->post_id)->first();

                if ($post === null || ! $post->isVisible()) {
                    throw SocialException::notFound('评论不存在或已删除');
                }

                return (int) $comment->user_id;

            case ReportTargetType::User:
                $user = User::query()->whereKey($targetId)->first();

                if ($user === null) {
                    throw SocialException::notFound('用户不存在');
                }

                return (int) $user->user_id;
        }
    }

    /**
     * 后台队列：默认待处理，先到先判；也可回看已处理/不成立。
     */
    public function paginate(?string $status, int $perPage = 20): LengthAwarePaginator
    {
        return Report::query()
            ->where('status', $status !== null && $status !== '' ? $status : ReportStatus::Pending->value)
            ->orderBy('report_id')
            ->paginate($perPage);
    }

    /**
     * 批量组装载荷：目标标题与归属人一次查完，避免队列页 N+1。
     *
     * @param  array<int, Report>  $reports
     * @return array<int, array<string, mixed>>
     */
    public function payloads(array $reports): array
    {
        $ids = [];
        foreach ($reports as $report) {
            $ids[$report->target_type->value][] = $report->target_id;
        }

        $listings = Listing::query()
            ->whereIn('listing_id', $ids[ReportTargetType::Listing->value] ?? [])
            ->get()
            ->keyBy('listing_id');
        $posts = Post::withTrashed()
            ->whereIn('post_id', $ids[ReportTargetType::Post->value] ?? [])
            ->get()
            ->keyBy('post_id');
        $comments = PostComment::withTrashed()
            ->whereIn('comment_id', $ids[ReportTargetType::Comment->value] ?? [])
            ->get()
            ->keyBy('comment_id');
        $users = User::query()
            ->whereIn('user_id', $ids[ReportTargetType::User->value] ?? [])
            ->get()
            ->keyBy('user_id');

        // 举报人 + 处置人一次取回（handled_by 可能为 null，过滤掉再查）
        $peopleIds = [];
        foreach ($reports as $report) {
            $peopleIds[] = (int) $report->reporter_id;
            if ($report->handled_by !== null) {
                $peopleIds[] = (int) $report->handled_by;
            }
        }

        $people = User::query()
            ->whereIn('user_id', array_values(array_unique($peopleIds)))
            ->get()
            ->keyBy('user_id');

        return array_map(function (Report $report) use ($listings, $posts, $comments, $users, $people) {
            [$title, $ownerId] = match ($report->target_type) {
                ReportTargetType::Listing => [
                    (string) ($listings[$report->target_id]->title ?? '（商品已删除）'),
                    (int) ($listings[$report->target_id]->seller_id ?? 0),
                ],
                ReportTargetType::Post => [
                    $this->postTitle($posts[$report->target_id] ?? null),
                    (int) ($posts[$report->target_id]->user_id ?? 0),
                ],
                ReportTargetType::Comment => [
                    $this->commentTitle($comments[$report->target_id] ?? null),
                    (int) ($comments[$report->target_id]->user_id ?? 0),
                ],
                ReportTargetType::User => [
                    (string) ($users[$report->target_id]->nickname ?? $users[$report->target_id]->username ?? '（用户已注销）'),
                    (int) $report->target_id,
                ],
            };

            return [
                'report_id' => (int) $report->report_id,
                'target_type' => $report->target_type->value,
                'target_type_label' => $report->target_type->label(),
                'target_id' => (int) $report->target_id,
                'target_title' => $title,
                'target_owner_id' => $ownerId,
                'reason' => $report->reason->value,
                'reason_label' => $report->reason->label(),
                'detail' => $report->detail,
                'status' => $report->status->value,
                'status_label' => $report->status->label(),
                'reporter' => $this->person($people[$report->reporter_id] ?? null),
                'handler' => $report->handled_by === null ? null : $this->person($people[$report->handled_by] ?? null),
                'handled_at' => $report->handled_at?->toIso8601String(),
                'handle_note' => $report->handle_note,
                'created_at' => $report->created_at?->toIso8601String(),
            ];
        }, $reports);
    }

    private function postTitle(?Post $post): string
    {
        if ($post === null) {
            return '（帖子已删除）';
        }

        $text = trim((string) $post->content);
        $text = $text === '' ? '（纯图片帖）' : mb_substr($text, 0, 60);

        return $post->trashed() ? '【已删除】'.$text : $text;
    }

    /** 评论标题：带「评论：」前缀，后台队列里一眼区分「报的是帖」还是「报的是某条评论」 */
    private function commentTitle(?PostComment $comment): string
    {
        if ($comment === null) {
            return '（评论已删除）';
        }

        $text = '评论：'.mb_substr(trim((string) $comment->content), 0, 60);

        return $comment->trashed() ? '【已删除】'.$text : $text;
    }

    /** @return array<string, mixed>|null */
    private function person(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'user_id' => (int) $user->user_id,
            'nickname' => $user->nickname,
            'username' => $user->username,
        ];
    }

    /**
     * 处置：只处理 pending；判定成立(handled)或不成立(dismissed)。
     *
     * @throws SocialException
     */
    public function handle(int $reportId, int $adminId, ReportStatus $result, ?string $note = null): Report
    {
        $report = Report::query()->whereKey($reportId)->first();

        if ($report === null) {
            throw SocialException::notFound('举报工单不存在');
        }

        if (! in_array($result, [ReportStatus::Handled, ReportStatus::Dismissed], true)) {
            throw SocialException::invalid('处置结论只能是成立或不成立');
        }

        if (! $report->isPending()) {
            throw SocialException::conflict('该举报已处理（当前：'.$report->status->label().'）');
        }

        return $report->transitTo($result, [
            'handled_by' => $adminId,
            'handled_at' => Carbon::now(),
            'handle_note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 255),
        ]);
    }
}

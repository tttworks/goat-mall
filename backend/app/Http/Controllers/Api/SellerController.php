<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Enums\ListingStatus;
use App\Enums\SellerStatus;
use App\Http\Requests\SellerApplyRequest;
use App\Http\Requests\SellerReviewRequest;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Identity\IdentityVerificationService;
use App\Services\Seller\SellerOnboardingService;
use App\Services\Social\FollowService;
use App\Services\Social\ReviewService;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 卖家开店接口（T2）。
 *
 * 流程：POST /seller/apply（资料 + 实名一次提交）→ 实名即时通过则店铺直接 active；
 * 实名 pending/失败则店铺停在 pending，实名通过后由 IdentityVerificationService 同步开通。
 */
class SellerController
{
    public function __construct(
        private readonly SellerOnboardingService $seller,
        private readonly IdentityVerificationService $identity,
        private readonly FollowService $follows,
        private readonly ReviewService $reviews,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->seller->profileOf($user);

        return response()->json([
            'seller' => $profile === null ? null : $this->payload($profile),
            'verification' => $this->identity->latest($user)?->toSafeArray(),
            'next_step' => $this->nextStep($profile),
        ]);
    }

    /**
     * 店铺主页（公开）：买家点商品里的卖家进来看到的就是这一页。
     *
     * 与 show() 的区别是「只出可公开字段」——实名状态、驳回理由属于经营内部信息，
     * 买家不需要也不应该看到。
     */
    public function publicShow(Request $request, int $seller): JsonResponse
    {
        $profile = SellerProfile::query()->where('user_id', $seller)->first();

        if ($profile === null) {
            return response()->json(['message' => '店铺不存在'], 404);
        }

        $summary = $this->reviews->summary($seller);

        // 在售数只算 active：待审/已售的商品出现在店铺页只会误导买家
        $onSale = Listing::query()
            ->where('seller_id', $seller)
            ->where('status', ListingStatus::Active->value)
            ->count();

        $viewer = $request->user();
        $isSelf = $viewer !== null && (int) $viewer->user_id === $seller;

        return response()->json([
            'data' => [
                'seller_id' => $seller,
                'shop_name' => $profile->shop_name,
                'shop_intro' => $profile->shop_intro,
                'logo_url' => MediaUrl::for($profile->shop_logo_key),
                'status' => $profile->status->value,
                'status_label' => $profile->status->label(),
                // rating_bp 是「5 星 = 10000」的基点，这里同时给 5 分制均值，前端不必各算一遍
                'rating' => $summary['count'] === 0 ? null : round($profile->rating_bp / 2000, 1),
                'review_count' => $summary['count'],
                'sold_count' => $profile->sold_count,
                'listing_count' => $onSale,
                'follower_count' => $this->follows->countFollowers($seller),
                'activated_at' => $profile->activated_at?->toIso8601String(),
            ],
            'is_self' => $isSelf,
            'following' => $isSelf || $viewer === null
                ? null
                : $this->follows->isFollowing((int) $viewer->user_id, $seller),
        ]);
    }

    public function apply(SellerApplyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // 已实名/审核中则不重复计费调用核身服务
        $verification = $this->identity->currentStatus($user)->canSubmit()
            ? $this->identity->submit(
                $user,
                (string) $request->input('real_name'),
                (string) $request->input('id_number'),
                (string) $request->ip(),
            )
            : $this->identity->latest($user);

        $profile = $this->seller->apply(
            $user,
            $request->safe()->only(['shop_name', 'shop_intro', 'shop_logo_key']),
            $verification,
        );

        return response()->json([
            'seller' => $this->payload($profile),
            'verification' => $verification?->toSafeArray(),
            'next_step' => $this->nextStep($profile),
        ], 201);
    }

    /** 后台店铺列表（admin）：默认待审核，可按状态筛选 */
    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:24'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = $this->seller->paginate(
            $validated['status'] ?? SellerStatus::Pending->value,
            (int) ($validated['per_page'] ?? 20)
        );

        return response()->json([
            'data' => array_map(function (SellerProfile $profile): array {
                return $this->payload($profile) + [
                    'owner' => [
                        'user_id' => (int) $profile->user_id,
                        'nickname' => $profile->user?->nickname,
                    ],
                ];
            }, $page->items()),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'statuses' => array_map(
                    static fn (SellerStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                    SellerStatus::cases()
                ),
            ],
        ]);
    }

    /** 后台审核（admin）：approve / reject / suspend */
    public function review(SellerReviewRequest $request, SellerProfile $seller): JsonResponse
    {
        $decision = (string) $request->input('decision');
        $reason = (string) $request->input('reason', '');

        $profile = match ($decision) {
            'approve' => $this->seller->approve($seller),
            'reject' => $this->seller->reject($seller, $reason),
            'suspend' => $this->seller->suspend($seller, $reason),
        };

        return response()->json([
            'seller' => $this->payload($profile),
            'decision' => $decision,
        ]);
    }

    // ---------------------------------------------------------------- 内部

    private function payload(SellerProfile $profile): array
    {
        return [
            'seller_id' => $profile->seller_id,
            // 对外导航（店铺主页 / 评价墙 / 关注）一律用 user_id：这三处的 seller 参数都是卖家账号
            'user_id' => (int) $profile->user_id,
            'shop_name' => $profile->shop_name,
            'shop_intro' => $profile->shop_intro,
            'shop_logo_key' => $profile->shop_logo_key,
            'status' => $profile->status->value,
            'status_label' => $profile->status->label(),
            'identity_status' => $profile->identity_status->value,
            'reject_reason' => $profile->reject_reason,
            'can_list' => $profile->canList(),
            'sold_count' => $profile->sold_count,
            'rating_bp' => $profile->rating_bp,
            'applied_at' => $profile->applied_at?->toIso8601String(),
            'activated_at' => $profile->activated_at?->toIso8601String(),
        ];
    }

    private function nextStep(?SellerProfile $profile): string
    {
        return match (true) {
            $profile === null => 'apply',
            $profile->status === SellerStatus::Suspended => 'appeal',
            $profile->status === SellerStatus::Active => 'list_items',
            $profile->identity_status->isApproved() => 'wait_review',
            default => 'wait_identity',
        };
    }
}

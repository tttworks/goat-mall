<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Enums\SellerStatus;
use App\Http\Requests\Seller\ApplyRequest;
use App\Http\Requests\Seller\ReviewRequest;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Identity\IdentityVerificationService;
use App\Services\Seller\SellerOnboardingService;
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

    public function apply(ApplyRequest $request): JsonResponse
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

    /** 后台审核（admin）：approve / reject / suspend */
    public function review(ReviewRequest $request, SellerProfile $seller): JsonResponse
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

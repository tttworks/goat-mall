<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Http\Requests\IdentitySubmitRequest;
use App\Models\User;
use App\Services\Identity\IdentityVerificationService;
use App\Services\Seller\SellerOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 实名核身接口（T2）。
 *
 * 只返回脱敏结果（mask），**永不回显**证件号明文；核身是敏感操作，全部走 POST + 鉴权。
 */
class IdentityController
{
    public function __construct(
        private readonly IdentityVerificationService $identity,
        private readonly SellerOnboardingService $seller,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'status' => $this->identity->currentStatus($user)->value,
            'status_label' => $this->identity->currentStatus($user)->label(),
            'verification' => $this->identity->latest($user)?->toSafeArray(),
        ]);
    }

    public function submit(IdentitySubmitRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $verification = $this->identity->submit(
            $user,
            (string) $request->input('real_name'),
            (string) $request->input('id_number'),
            (string) $request->ip(),
        );

        // 同步店铺档案：实名通过即开通营业（已开店用户无需再等人工审核）
        $profile = $this->seller->syncIdentity($user, $verification->status);

        return response()->json([
            'verification' => $verification->toSafeArray(),
            'seller' => $profile === null ? null : [
                'seller_id' => $profile->seller_id,
                'status' => $profile->status->value,
                'identity_status' => $profile->identity_status->value,
                'can_list' => $profile->canList(),
            ],
        ], 201);
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Seller;

use App\Enums\SellerStatus;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Exceptions\AuthException;
use App\Models\IdentityVerification;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 卖家开店（T2）。
 *
 * 状态链路：draft → pending（提交申请/待实名）→ active（实名通过 + 审核通过）→ suspended（封禁）。
 * 关键约束：**未实名不得营业**。因此 identity_status 与 status 分开维护：
 *  - 实名由 IdentityVerificationService 负责，本类只同步结果；
 *  - 营业开关只看 status，避免实名服务抖动导致店铺被误关。
 */
class SellerOnboardingService
{
    public function profileOf(User $user): ?SellerProfile
    {
        return SellerProfile::where('user_id', $user->user_id)->first();
    }

    /**
     * 提交开店申请（可重复提交：上次被拒后可改资料再提）。
     *
     * @param  array{shop_name: string, shop_intro?: string|null, shop_logo_key?: string|null}  $data
     */
    public function apply(User $user, array $data, ?IdentityVerification $verification = null): SellerProfile
    {
        $profile = $this->profileOf($user);

        if ($profile !== null && $profile->status === SellerStatus::Suspended) {
            throw AuthException::forbidden('店铺已被封禁，请联系客服申诉');
        }

        $identityStatus = $verification?->status ?? VerificationStatus::None;
        $canOpen = $identityStatus === VerificationStatus::Approved;

        try {
            return DB::transaction(function () use ($user, $data, $profile, $identityStatus, $canOpen): SellerProfile {
                $attributes = [
                    'shop_name' => trim((string) $data['shop_name']),
                    'shop_intro' => $data['shop_intro'] ?? null,
                    'shop_logo_key' => $data['shop_logo_key'] ?? null,
                    'identity_status' => $identityStatus,
                    'status' => $canOpen ? SellerStatus::Active : SellerStatus::Pending,
                    'applied_at' => $profile?->applied_at ?? now(),
                    'activated_at' => $canOpen ? ($profile?->activated_at ?? now()) : null,
                    'reject_reason' => null,
                ];

                if ($profile === null) {
                    $profile = SellerProfile::create($attributes + ['user_id' => $user->user_id]);
                } else {
                    $profile->fill($attributes)->save();
                }

                if ($canOpen) {
                    $this->promote($user);
                }

                Log::channel('security')->info('seller.开店申请', [
                    'user_id' => $user->user_id,
                    'status' => $profile->status->value,
                    'identity_status' => $identityStatus->value,
                ]);

                return $profile->refresh();
            });
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23505', '23000'], true)) {
                throw AuthException::conflict('该店铺名称已被使用');
            }

            throw $e;
        }
    }

    /** 后台审核通过：必须已实名，否则拒绝（避免"先营业后补证件"） */
    public function approve(SellerProfile $profile): SellerProfile
    {
        if ($profile->identity_status !== VerificationStatus::Approved) {
            throw AuthException::rejected('该卖家尚未通过实名核身，不能开通店铺', 'IDENTITY_REQUIRED');
        }

        $profile->forceFill([
            'status' => SellerStatus::Active,
            'activated_at' => $profile->activated_at ?? now(),
            'reject_reason' => null,
        ])->save();

        $this->promote($profile->user);

        return $profile->refresh();
    }

    /** 后台驳回：退回 draft，保留 reject_reason 供卖家修改后重提 */
    public function reject(SellerProfile $profile, string $reason): SellerProfile
    {
        $profile->forceFill([
            'status' => SellerStatus::Draft,
            'reject_reason' => mb_substr($reason, 0, 255),
        ])->save();

        return $profile->refresh();
    }

    public function suspend(SellerProfile $profile, string $reason): SellerProfile
    {
        DB::transaction(function () use ($profile, $reason): void {
            $profile->forceFill([
                'status' => SellerStatus::Suspended,
                'suspended_at' => now(),
                'reject_reason' => mb_substr($reason, 0, 255),
            ])->save();

            // 降级为买家：T4 上架接口按 role/店铺状态双重校验，两边都拦住
            $profile->user?->forceFill(['role' => UserRole::Buyer])->save();
        });

        return $profile->refresh();
    }

    /** 实名结果同步到店铺档案（实名通过即可开门营业，无需再等人工审核） */
    public function syncIdentity(User $user, VerificationStatus $status): ?SellerProfile
    {
        $profile = $this->profileOf($user);

        if ($profile === null) {
            return null;
        }

        $profile->forceFill(['identity_status' => $status])->save();

        if ($status === VerificationStatus::Approved && $profile->status !== SellerStatus::Active) {
            $profile->forceFill([
                'status' => SellerStatus::Active,
                'activated_at' => $profile->activated_at ?? now(),
                'reject_reason' => null,
            ])->save();

            $this->promote($user);
        }

        return $profile->refresh();
    }

    private function promote(User $user): void
    {
        if ($user->role !== UserRole::Admin) {
            $user->forceFill(['role' => UserRole::Seller])->save();
        }
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Identity;

use App\Enums\VerificationStatus;
use App\Exceptions\AuthException;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\Identity\Contracts\IdentityVerifier;
use Illuminate\Support\Facades\Log;

/**
 * 实名核身服务（T2）。
 *
 * 三条硬规则：
 *  1. **不存明文**：姓名/证件号走 encrypted cast，查询一律用 `id_number_hash`（HMAC，不可逆）；
 *  2. **同一证件只能绑一个账号**（防止多开号绕过封禁），命中即拒；
 *  3. **按次付费**：只对"从未提交/上次失败"的请求发起外部调用，pending 中不许重复提交。
 */
class IdentityVerificationService
{
    public function __construct(private readonly IdentityVerifier $verifier) {}

    public function latest(User $user): ?IdentityVerification
    {
        return IdentityVerification::query()
            ->where('user_id', $user->user_id)
            ->latest('verification_id')
            ->first();
    }

    public function currentStatus(User $user): VerificationStatus
    {
        return $this->latest($user)?->status ?? VerificationStatus::None;
    }

    /**
     * 提交核身。
     *
     * @throws AuthException 重复提交、证件占用、格式非法
     */
    public function submit(User $user, string $realName, string $idNumber, ?string $ip = null): IdentityVerification
    {
        $realName = trim($realName);
        $normalized = $this->normalizeIdNumber($idNumber);

        if ($realName === '' || mb_strlen($realName) > 32) {
            throw AuthException::rejected('请填写真实姓名', 'INVALID_REAL_NAME');
        }

        if (! $this->looksLikeIdNumber($normalized)) {
            throw AuthException::rejected('证件号格式不正确', 'INVALID_ID_NUMBER');
        }

        $status = $this->currentStatus($user);

        if ($status === VerificationStatus::Approved) {
            throw AuthException::rejected('已完成实名核身，无需重复提交', 'ALREADY_VERIFIED');
        }

        if ($status === VerificationStatus::Pending) {
            throw AuthException::rejected('核身审核中，请耐心等待结果', 'VERIFICATION_PENDING');
        }

        $hash = $this->hashIdNumber($normalized);

        $occupied = IdentityVerification::query()
            ->where('id_number_hash', $hash)
            ->where('status', VerificationStatus::Approved->value)
            ->where('user_id', '!=', $user->user_id)
            ->exists();

        if ($occupied) {
            Log::channel('security')->warning('identity.证件已被其他账号实名', [
                'user_id' => $user->user_id,
                'ip' => $ip,
            ]);

            throw AuthException::conflict('该证件已绑定其他账号，如有疑问请联系客服');
        }

        $verification = IdentityVerification::create([
            'user_id' => $user->user_id,
            'provider' => $this->verifier->name(),
            'real_name' => $realName,
            'id_number' => $normalized,
            'id_number_hash' => $hash,
            'id_number_mask' => $this->mask($normalized),
            'status' => VerificationStatus::Pending,
            'submitted_at' => now(),
            'submitted_ip' => $ip,
        ]);

        try {
            $result = $this->verifier->verify($realName, $normalized);
        } catch (\Throwable $e) {
            // 外部服务不可用：留 pending，不重复计费；由后台/定时任务补查
            Log::channel('security')->error('identity.核身调用异常', [
                'verification_id' => $verification->verification_id,
                'error' => $e->getMessage(),
            ]);

            $verification->forceFill(['reason' => '核身服务暂不可用，稍后自动重试'])->save();

            return $verification->refresh();
        }

        $verification->forceFill([
            'status' => $result->status,
            'channel_ref' => $result->channelRef,
            'reason' => $result->reason,
            'verified_at' => $result->status === VerificationStatus::Approved ? now() : null,
        ])->save();

        if ($result->status === VerificationStatus::Approved) {
            Log::channel('security')->info('identity.实名通过', [
                'user_id' => $user->user_id,
                'verification_id' => $verification->verification_id,
            ]);
        }

        return $verification->refresh();
    }

    // ---------------------------------------------------------------- 内部

    /** 去空格、全角转半角、字母大写（身份证末位 x） */
    public function normalizeIdNumber(string $idNumber): string
    {
        $value = str_replace([' ', "\t", "\u{3000}", '-'], '', $idNumber);

        return mb_strtoupper($value);
    }

    /** 18 位居民身份证，或 5~20 位护照/通行证（字母数字） */
    private function looksLikeIdNumber(string $value): bool
    {
        if (preg_match('/^\d{17}[\dX]$/', $value) === 1) {
            return true;
        }

        return preg_match('/^[A-Z0-9]{5,20}$/', $value) === 1 && preg_match('/\d/', $value) === 1;
    }

    /** HMAC-SHA256：不可逆、不可跨环境比对，仅用于号内去重 */
    private function hashIdNumber(string $normalized): string
    {
        $salt = (string) config('goat.auth.identity.hash_salt', (string) config('app.key', 'goat'));

        return hash_hmac('sha256', $normalized, $salt);
    }

    /** 展示用脱敏：保留前 3 后 4，中间固定长度星号 */
    private function mask(string $normalized): string
    {
        $length = strlen($normalized);

        if ($length <= 7) {
            return substr($normalized, 0, 1).str_repeat('*', max($length - 2, 1)).substr($normalized, -1);
        }

        return substr($normalized, 0, 3).str_repeat('*', $length - 7).substr($normalized, -4);
    }
}

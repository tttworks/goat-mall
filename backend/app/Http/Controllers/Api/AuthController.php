<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Enums\AuthProvider;
use App\Http\Requests\AuthAppleLoginRequest;
use App\Http\Requests\AuthLoginRequest;
use App\Http\Requests\AuthRegisterRequest;
use App\Http\Requests\AuthWechatLoginRequest;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 认证接口（T2）：四通道注册/登录、当前用户、登出。
 *
 * 返回约定：登录成功统一返回 `{token, token_type, expires_in, user}`，
 * 前端只需存 token；`expires_in` 单位秒，便于提前静默续期。
 */
class AuthController
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(AuthRegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register(
            $request->safe()->only(['username', 'email', 'password', 'nickname', 'locale']),
            (string) $request->ip(),
        );

        return $this->issued($user, $request->input('device_name'));
    }

    public function login(AuthLoginRequest $request): JsonResponse
    {
        $user = $this->auth->login(
            (string) $request->input('login'),
            (string) $request->input('password'),
            (string) $request->ip(),
        );

        return $this->issued($user, $request->input('device_name'));
    }

    public function wechat(AuthWechatLoginRequest $request): JsonResponse
    {
        $user = $this->auth->loginWithWechat(
            (string) $request->input('code'),
            $request->input('nickname'),
            (string) $request->ip(),
        );

        return $this->issued($user, $request->input('device_name'));
    }

    public function apple(AuthAppleLoginRequest $request): JsonResponse
    {
        $user = $this->auth->loginWithApple(
            (string) $request->input('identity_token'),
            $request->input('nickname'),
            (string) $request->ip(),
        );

        return $this->issued($user, $request->input('device_name'));
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->profile($user),
            'channels' => $user->identities->map(fn ($identity): array => [
                'provider' => $identity->provider->value,
                'label' => $identity->provider->label(),
                'verified' => $identity->verified_at !== null,
            ])->values(),
            'seller' => $user->sellerProfile === null ? null : [
                'seller_id' => $user->sellerProfile->seller_id,
                'shop_name' => $user->sellerProfile->shop_name,
                'status' => $user->sellerProfile->status->value,
                'status_label' => $user->sellerProfile->status->label(),
                'identity_status' => $user->sellerProfile->identity_status->value,
                'can_list' => $user->sellerProfile->canList(),
            ],
            'available_channels' => collect(AuthProvider::cases())
                ->filter(fn (AuthProvider $p): bool => $p->enabled())
                ->map(fn (AuthProvider $p): string => $p->value)
                ->values(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->auth->revokeCurrentToken($user);

        return response()->json(['message' => '已登出']);
    }

    // ---------------------------------------------------------------- 内部

    private function issued(User $user, ?string $deviceName): JsonResponse
    {
        $token = $this->auth->issueToken($user, $deviceName);
        $ttl = (int) config('goat.auth.token.ttl_minutes', 43200);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttl > 0 ? $ttl * 60 : null,
            'user' => $this->profile($user->refresh()),
        ], 201);
    }

    /** 本人可见的完整资料（含 email，但不含 password/token） */
    private function profile(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone, // 预留字段，短信未启用时恒为 null
            'nickname' => $user->nickname,
            'avatar_key' => $user->avatar_key,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'locale' => $user->locale,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Auth;

use App\Enums\AuthProvider;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\AuthException;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * 认证服务（T2）：四通道注册/登录 + Token 签发。
 *
 * 设计取舍：
 *  - 注册与登录都拆成「建 user」+「建 identity」两步，OAuth 首登复用同一路径；
 *  - 唯一键冲突统一转成 409，而不是把 DB 异常抛给前端（并发重复注册必须表现为友好错误）；
 *  - 登录失败不区分「账号不存在」与「密码错误」，避免账号枚举。
 *  - 不在本类里做限流：限流属于 HTTP 层（route middleware throttle），便于按 IP/设备调整。
 */
class AuthService
{
    public function __construct(
        private readonly WechatClient $wechat,
        private readonly AppleTokenVerifier $apple,
    ) {}

    // ---------------------------------------------------------------- 本地通道

    /**
     * 用户名 / 邮箱注册。
     *
     * @param  array{username?: string, email?: string, password: string, nickname?: string, locale?: string}  $data
     */
    public function register(array $data, string $ip): User
    {
        $username = isset($data['username']) && $data['username'] !== '' ? trim((string) $data['username']) : null;
        $email = isset($data['email']) && $data['email'] !== '' ? mb_strtolower(trim((string) $data['email'])) : null;

        if ($username === null && $email === null) {
            throw AuthException::rejected('请提供用户名或邮箱', 'MISSING_IDENTIFIER');
        }

        $provider = $username !== null ? AuthProvider::Username : AuthProvider::Email;

        if (! $provider->enabled()) {
            throw AuthException::channelDisabled($provider->label());
        }

        if ($username !== null && User::withTrashed()->where('username', $username)->exists()) {
            throw AuthException::conflict('该用户名已被占用');
        }

        if ($email !== null && User::withTrashed()->where('email', $email)->exists()) {
            throw AuthException::conflict('该邮箱已被注册');
        }

        try {
            return DB::transaction(function () use ($username, $email, $data, $ip, $provider): User {
                $user = User::create([
                    'username' => $username,
                    'email' => $email,
                    'password' => $data['password'],
                    'nickname' => $data['nickname'] ?? $username ?? $this->emailLocalPart((string) $email),
                    'role' => UserRole::Buyer,
                    'status' => UserStatus::Active,
                    'locale' => $data['locale'] ?? (string) config('app.locale', 'zh_CN'),
                ]);

                $user->identities()->create([
                    'provider' => $provider,
                    'provider_user_id' => (string) ($username ?? $email),
                    'nickname' => $user->nickname,
                    // 邮箱通道暂未启用验证邮件，先不置 verified_at；用户名通道即时可用
                    'verified_at' => $provider === AuthProvider::Username ? now() : null,
                ]);

                $this->touchLogin($user, $ip);

                return $user;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw AuthException::conflict('该账号已被注册');
            }

            throw $e;
        }
    }

    /** 用户名或邮箱 + 密码登录 */
    public function login(string $login, string $password, string $ip): User
    {
        $login = trim($login);

        $user = User::query()
            ->where('username', $login)
            ->orWhere('email', mb_strtolower($login))
            ->first();

        if ($user === null || $user->password === null || ! Hash::check($password, $user->password)) {
            // 统一文案 + 安全日志（不打印密码/账号原文，只记 IP）
            Log::channel('security')->info('auth.login 失败', ['ip' => $ip]);

            throw AuthException::invalidCredentials();
        }

        $this->assertActive($user);
        $this->touchLogin($user, $ip);

        return $user;
    }

    // ---------------------------------------------------------------- OAuth 通道

    /** 微信小程序登录：code → openid → 查绑定或建号 */
    public function loginWithWechat(string $code, ?string $nickname, string $ip): User
    {
        if (! AuthProvider::Wechat->enabled()) {
            throw AuthException::channelDisabled(AuthProvider::Wechat->label());
        }

        $session = $this->wechat->code2Session($code);
        $unionId = $session['unionid'];

        $identity = Identity::query()
            ->where('provider', AuthProvider::Wechat)
            ->where('provider_user_id', $session['openid'])
            ->first();

        if ($identity !== null) {
            $user = $identity->user;
            $this->assertActive($user);

            $identity->forceFill([
                'union_id' => $identity->union_id ?: $unionId,
                'last_login_at' => now(),
            ])->save();

            $this->touchLogin($user, $ip);

            return $user;
        }

        // unionid 已绑定过其他 openid（同微信多端）→ 复用原账号，避免一人两号
        $linked = $unionId !== null
            ? Identity::where('provider', AuthProvider::Wechat)->where('union_id', $unionId)->first()
            : null;

        if ($linked !== null) {
            $user = $linked->user;
            $this->assertActive($user);

            $user->identities()->create([
                'provider' => AuthProvider::Wechat,
                'provider_user_id' => $session['openid'],
                'union_id' => $unionId,
                'last_login_at' => now(),
            ]);

            $this->touchLogin($user, $ip);

            return $user;
        }

        return DB::transaction(function () use ($session, $nickname, $ip): User {
            $user = User::create([
                'nickname' => $nickname ?: '微信用户',
                'role' => UserRole::Buyer,
                'status' => UserStatus::Active,
                'locale' => (string) config('app.locale', 'zh_CN'),
            ]);

            $user->identities()->create([
                'provider' => AuthProvider::Wechat,
                'provider_user_id' => $session['openid'],
                'union_id' => $session['unionid'],
                'nickname' => $user->nickname,
                'verified_at' => now(), // 微信侧已实名，本通道视为已验证
                'last_login_at' => now(),
            ]);

            $this->touchLogin($user, $ip);

            return $user;
        });
    }

    /** Apple 登录：校验 identityToken → sub 查绑定；邮箱可信时自动并号 */
    public function loginWithApple(string $identityToken, ?string $nickname, string $ip): User
    {
        if (! AuthProvider::Apple->enabled()) {
            throw AuthException::channelDisabled(AuthProvider::Apple->label());
        }

        $claims = $this->apple->verify($identityToken);
        $sub = (string) $claims['sub'];

        $identity = Identity::query()
            ->where('provider', AuthProvider::Apple)
            ->where('provider_user_id', $sub)
            ->first();

        if ($identity !== null) {
            $user = $identity->user;
            $this->assertActive($user);
            $identity->forceFill(['last_login_at' => now()])->save();
            $this->touchLogin($user, $ip);

            return $user;
        }

        $email = isset($claims['email']) ? mb_strtolower((string) $claims['email']) : null;
        $emailVerified = (bool) ($claims['email_verified'] ?? false);
        $isPrivate = (bool) ($claims['is_private_email'] ?? false);

        // 仅「邮箱已由 Apple 验证且非中继邮箱」才允许并到已有账号，否则可能被顶号
        $existing = ($email !== null && $emailVerified && ! $isPrivate)
            ? User::where('email', $email)->first()
            : null;

        if ($existing !== null) {
            $this->assertActive($existing);

            $existing->identities()->create([
                'provider' => AuthProvider::Apple,
                'provider_user_id' => $sub,
                'nickname' => $nickname,
                'meta' => ['is_private_email' => $isPrivate, 'linked_by' => 'email'],
                'verified_at' => now(),
                'last_login_at' => now(),
            ]);

            $this->touchLogin($existing, $ip);

            return $existing;
        }

        return DB::transaction(function () use ($sub, $email, $emailVerified, $isPrivate, $nickname, $ip): User {
            $user = User::create([
                'email' => $email,
                'nickname' => $nickname ?: ($email !== null ? $this->emailLocalPart($email) : 'Apple 用户'),
                'role' => UserRole::Buyer,
                'status' => UserStatus::Active,
                'locale' => (string) config('app.locale', 'zh_CN'),
            ]);

            $user->identities()->create([
                'provider' => AuthProvider::Apple,
                'provider_user_id' => $sub,
                'nickname' => $user->nickname,
                'meta' => ['is_private_email' => $isPrivate, 'email_verified' => $emailVerified],
                'verified_at' => now(),
                'last_login_at' => now(),
            ]);

            $this->touchLogin($user, $ip);

            return $user;
        });
    }

    // ---------------------------------------------------------------- Token

    /** 签发 Sanctum Token；返回明文（仅此一次可见） */
    public function issueToken(User $user, ?string $deviceName = null): string
    {
        $ttl = (int) config('goat.auth.token.ttl_minutes', 43200); // 默认 30 天：C 端低频访问，过短体验差
        $expiresAt = $ttl > 0 ? now()->addMinutes($ttl) : null;

        return $user->createToken($deviceName ?: 'goat-api', ['*'], $expiresAt)->plainTextToken;
    }

    /** 注销当前请求所用的 Token（其他设备保持登录） */
    public function revokeCurrentToken(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }
    }

    // ---------------------------------------------------------------- 内部

    private function assertActive(User $user): void
    {
        if (! $user->canTransact()) {
            throw AuthException::forbidden('账号已被封禁，请联系客服');
        }
    }

    private function touchLogin(User $user, string $ip): void
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();
    }

    private function emailLocalPart(string $email): string
    {
        $parts = explode('@', $email);

        return $parts[0] !== '' ? $parts[0] : 'goat-user';
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // PG: 23505 / SQLite: UNIQUE constraint failed / MySQL: 1062
        return in_array((string) $e->getCode(), ['23505', '23000'], true)
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}

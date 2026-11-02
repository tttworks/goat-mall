<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 首个运营管理员（admin-pc 登录用）。
 *
 * 为什么不写死密码：仓库里出现明文口令，等于把后台钥匙一起提交了。
 * 口令来源优先级 `GOAT_ADMIN_PASSWORD` → 随机生成并打印一次 → 由运营自己保存。
 *
 * 幂等语义：
 * - 账号已存在：只补角色（买家 → 管理员）与状态（恢复 active），**不动已有密码**；
 * - 账号已存在且本次显式配了 `GOAT_ADMIN_PASSWORD`：按配置重置口令（这就是找回入口）。
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = trim((string) config('goat.admin.username', 'admin'));
        $username = $username !== '' ? $username : 'admin';

        $email = trim((string) config('goat.admin.email', ''));
        $email = $email !== '' ? mb_strtolower($email) : null;

        $password = (string) config('goat.admin.password', '');
        $hasPassword = $password !== '';

        $user = User::withTrashed()
            ->where(function ($query) use ($username, $email): void {
                $query->where('username', $username);
                if ($email !== null) {
                    $query->orWhere('email', $email);
                }
            })
            ->first();

        if ($user !== null) {
            $this->promote($user, $email, $hasPassword ? $password : null);

            $this->command?->warn(sprintf(
                '管理员已存在（user_id=%d, username=%s），已确保角色为 admin；密码%s。',
                $user->user_id,
                (string) $user->username,
                $hasPassword ? '按 GOAT_ADMIN_PASSWORD 重置' : '保持原样',
            ));

            return;
        }

        $plain = $hasPassword ? $password : Str::password(20);

        $user = User::create([
            'username' => $username,
            'email' => $email,
            'password' => $plain,
            'nickname' => '运营管理员',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
            'locale' => (string) config('app.locale', 'zh_CN'),
        ]);

        $this->command?->info('已创建运营管理员：');
        $this->command?->line('  账号：'.$username.($email !== null ? '（或 '.$email.'）' : ''));

        if ($hasPassword) {
            $this->command?->line('  密码：使用 GOAT_ADMIN_PASSWORD 配置值');
        } else {
            // 只打印一次：这是运营拿到初始口令的唯一机会，落库后再也取不出来（只存哈希）
            $this->command?->warn('  初始密码：'.$plain);
            $this->command?->line('  请立即保存；遗忘时配置 GOAT_ADMIN_PASSWORD 后重跑本 Seeder 可重置。');
        }
    }

    /** 已存在的账号：只补角色/状态，必要时重置口令 */
    private function promote(User $user, ?string $email, ?string $password): void
    {
        if ($user->trashed()) {
            $user->restore();
        }

        $user->role = UserRole::Admin;
        $user->status = UserStatus::Active;

        if ($email !== null && $user->email === null) {
            $user->email = $email;
        }

        if ($password !== null) {
            $user->password = $password;
        }

        $user->save();
    }
}

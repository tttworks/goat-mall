<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 认证主链路特征测试（T2）。
 *
 * 覆盖合同验收点：四通道中的用户名/邮箱密码通道可注册可登录、
 * 重复注册返回 409（不是 500）、错误密码不泄漏账号是否存在、登出后 Token 失效。
 */
class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Goat@2026';

    public function test_register_returns_token_and_creates_identity(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'goat_fan',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'nickname' => '潮玩玩家',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'expires_in', 'user' => ['user_id', 'username', 'role', 'status']]);

        $this->assertSame('buyer', $response->json('user.role'));
        $this->assertDatabaseHas('users', ['username' => 'goat_fan', 'role' => 'buyer', 'status' => 'active']);
        $this->assertDatabaseHas('identities', ['provider' => 'username', 'provider_user_id' => 'goat_fan']);
    }

    public function test_register_requires_username_or_email(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors(['username']);
    }

    public function test_duplicate_username_returns_conflict(): void
    {
        User::create([
            'username' => 'taken_name',
            'password' => self::PASSWORD,
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/auth/register', [
            'username' => 'taken_name',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertStatus(409)->assertJson(['code' => 'CONFLICT']);
    }

    public function test_login_then_me_then_logout(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'fan@goat.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertCreated();

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'fan@goat.test',
            'password' => self::PASSWORD,
            'device_name' => 'iphone-15',
        ])->assertCreated();

        $token = (string) $login->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'fan@goat.test');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_wrong_password_is_rejected_without_leaking_existence(): void
    {
        User::create([
            'username' => 'real_user',
            'password' => self::PASSWORD,
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'real_user',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJson(['code' => 'INVALID_CREDENTIALS']);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'no_such_user',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJson(['code' => 'INVALID_CREDENTIALS']);
    }

    public function test_suspended_user_cannot_login(): void
    {
        User::create([
            'username' => 'banned_user',
            'password' => self::PASSWORD,
            'role' => 'buyer',
            'status' => 'suspended',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'banned_user',
            'password' => self::PASSWORD,
        ])->assertStatus(403)->assertJson(['code' => 'FORBIDDEN']);
    }
}

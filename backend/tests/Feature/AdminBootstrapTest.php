<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 后台开箱链路：Seeder 建号 → 账号能登录 → 非管理员被挡在 role:admin 之外。
 *
 * 这条链断在任一环，运营都进不去 admin-pc，所以用测试钉住而不是靠人工试。
 */
class AdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'goat.admin.username' => 'goat_admin',
            'goat.admin.email' => 'ops@goat.test',
            'goat.admin.password' => 'Goat@2026!',
        ]);
    }

    public function test_seeder_creates_admin_that_can_login(): void
    {
        $this->seed(AdminUserSeeder::class);

        $user = User::query()->where('username', 'goat_admin')->firstOrFail();

        $this->assertSame('admin', $user->role->value);
        $this->assertSame('active', $user->status->value);
        $this->assertSame('ops@goat.test', $user->email);
        $this->assertTrue(Hash::check('Goat@2026!', $user->password));

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'goat_admin',
            'password' => 'Goat@2026!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonStructure(['token', 'token_type', 'expires_in', 'user']);
    }

    public function test_seeder_is_idempotent_and_promotes_existing_account(): void
    {
        User::query()->create([
            'username' => 'goat_admin',
            'password' => 'Original@2026',
            'role' => 'buyer',
            'status' => 'suspended',
        ]);

        $this->seed(AdminUserSeeder::class);

        // 显式配置了密码 = 重置；角色与状态一并补齐
        $user = User::query()->where('username', 'goat_admin')->firstOrFail();
        $this->assertSame('admin', $user->role->value);
        $this->assertSame('active', $user->status->value);
        $this->assertTrue(Hash::check('Goat@2026!', $user->password));

        $this->seed(AdminUserSeeder::class);
        $this->assertSame(1, User::query()->where('username', 'goat_admin')->count());
    }

    public function test_seeder_keeps_existing_password_when_not_configured(): void
    {
        User::query()->create([
            'username' => 'goat_admin',
            'password' => 'Original@2026',
            'role' => 'buyer',
            'status' => 'active',
        ]);

        config(['goat.admin.password' => null]);

        $this->seed(AdminUserSeeder::class);

        $user = User::query()->where('username', 'goat_admin')->firstOrFail();
        $this->assertSame('admin', $user->role->value);
        // 运营改过的密码不能被一次误跑 Seeder 冲掉
        $this->assertTrue(Hash::check('Original@2026', $user->password));
    }
}

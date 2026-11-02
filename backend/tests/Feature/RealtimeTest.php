<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Events\NotificationCreated;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Community\ImService;
use App\Services\Notify\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 实时推送（T6 · Reverb）。
 *
 * 验证四件事：
 * 1. 端点是**公开可读**，但只下发公开 app key（secret 永不出网）；
 * 2. 私有频道鉴权挂在 sanctum 上，且只能订阅自己的频道；
 * 3. ws 开关关闭时事件根本不派发（不往队列里堆垃圾 job）；
 * 4. 通知去重命中时不再重复广播（渠道重推不刷屏）。
 *
 * 注：`BROADCAST_CONNECTION=null` 是测试默认值，这里显式切到 reverb + 假密钥，
 * 让签名在本地算出来（Pusher 协议的签名不需要联网），从而能校验签名正确性。
 */
class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'goat.ws.app_key' => 'test-key',
        ]);

        // 频道回调查询 `Broadcast::channel()` 时注册在「当时已解析的驱动」上。
        // 本测试把默认驱动从 null 切成 reverb，等于换了一个驱动实例，
        // 因此需要把 channels.php 在新驱动上重放一遍——这不是业务代码的问题，
        // 生产环境 BROADCAST_CONNECTION 本身就是 reverb，只注册一次。
        require base_path('routes/channels.php');
    }

    private function makeUser(string $username): User
    {
        return User::query()->create([
            'username' => $username,
            'password' => 'Goat@2026',
            'role' => 'buyer',
            'status' => 'active',
        ]);
    }

    public function test_realtime_config_is_public_and_never_leaks_secret(): void
    {
        config(['goat.ws.enabled' => true]);

        $response = $this->getJson('/api/v1/realtime/config')->assertOk();

        $response->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.key', 'test-key');

        // secret 不在任何层级出现：这是最容易犯的错（把 .env 整段塞给前端）
        $this->assertArrayNotHasKey('secret', $response->json('data'));
        $this->assertStringNotContainsString('secret', (string) json_encode($response->json('data')));
    }

    public function test_realtime_disabled_when_ws_switch_off(): void
    {
        config(['goat.ws.enabled' => false]);

        $this->getJson('/api/v1/realtime/config')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
    }

    public function test_broadcast_auth_requires_token(): void
    {
        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123456.789012',
            'channel_name' => 'private-goat.user.1',
        ])->assertStatus(401);
    }

    public function test_broadcast_auth_signs_own_private_channel(): void
    {
        $user = $this->makeUser('goat_ws_owner');
        Sanctum::actingAs($user);

        $socketId = '123456.789012';
        $channel = 'private-goat.user.'.$user->user_id;

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => $socketId,
            'channel_name' => $channel,
        ])->assertOk();

        $expected = 'test-key:'.hash_hmac('sha256', $socketId.':'.$channel, 'test-secret');

        $this->assertSame($expected, $response->json('auth'));
    }

    public function test_broadcast_auth_rejects_foreign_user_channel(): void
    {
        $user = $this->makeUser('goat_ws_intruder');
        $other = $this->makeUser('goat_ws_victim');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123456.789012',
            'channel_name' => 'private-goat.user.'.$other->user_id,
        ])->assertStatus(403);
    }

    public function test_message_send_dispatches_event_only_when_ws_enabled(): void
    {
        $a = $this->makeUser('goat_im_a');
        $b = $this->makeUser('goat_im_b');

        [$low, $high] = Conversation::normalize($a->user_id, $b->user_id);
        $conversation = Conversation::query()->create([
            'user_a_id' => $low,
            'user_b_id' => $high,
        ]);

        Event::fake([MessageSent::class]);
        $im = app(ImService::class);

        config(['goat.ws.enabled' => false]);
        $im->send($a->user_id, $conversation->conversation_id, '关闭时不该广播');
        Event::assertNotDispatched(MessageSent::class);

        config(['goat.ws.enabled' => true]);
        $im->send($a->user_id, $conversation->conversation_id, '打开时推给对端');
        Event::assertDispatchedTimes(MessageSent::class, 1);
    }

    public function test_notification_broadcasts_once_and_skips_dedupe_replay(): void
    {
        config(['goat.ws.enabled' => true]);

        $user = $this->makeUser('goat_notify_ws');
        Event::fake([NotificationCreated::class]);

        $service = app(NotificationService::class);
        $service->push($user->user_id, 'order.paid', '订单已付款', '资金已冻结', [], 'order:1:paid');
        $service->push($user->user_id, 'order.paid', '订单已付款', '资金已冻结', [], 'order:1:paid');

        Event::assertDispatchedTimes(NotificationCreated::class, 1);
    }
}

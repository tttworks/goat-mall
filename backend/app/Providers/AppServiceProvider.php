<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Providers;

use App\Services\Auth\AppleTokenVerifier;
use App\Services\Auth\WechatClient;
use App\Services\Identity\Contracts\IdentityVerifier;
use App\Services\Identity\Drivers\HttpVerifier;
use App\Services\Identity\Drivers\NullVerifier;
use App\Services\Payment\Contracts\PaymentChannel;
use App\Services\Payment\Drivers\SandboxChannel;
use App\Services\Payment\PaymentService;
use App\Services\Trade\BiddingService;
use App\Services\Trade\FeeCalculator;
use App\Services\Trade\OrderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * 全局引导（T1）：严格模式、HTTPS 强制、交易域服务单例。
 * 说明：交易服务是**无状态**的（状态全在 PG 行锁里），单例只为省重复实例化，不影响并发正确性。
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeeCalculator::class);
        $this->app->singleton(OrderService::class);
        $this->app->singleton(BiddingService::class);

        // 认证域（T2）：OAuth 客户端无状态，可安全单例
        $this->app->singleton(WechatClient::class);
        $this->app->singleton(AppleTokenVerifier::class);

        // 实名核身驱动按配置解析：换服务商只改 GOAT_IDENTITY_DRIVER，业务代码不动
        $this->app->bind(IdentityVerifier::class, function (): IdentityVerifier {
            return match ((string) config('goat.auth.identity.driver', 'null')) {
                'http' => new HttpVerifier,
                default => new NullVerifier,
            };
        });

        // 支付通道（T5）：sandbox 与真实收付通实现同一契约，业务层不感知
        $this->app->bind(PaymentChannel::class, function (): PaymentChannel {
            return match ((string) config('goat.payment.channel', 'sandbox')) {
                default => new SandboxChannel(
                    (string) config('goat.payment.sandbox.secret'),
                    (int) config('goat.payment.sandbox.notify_tolerance', 300),
                ),
            };
        });

        $this->app->singleton(PaymentService::class);
    }

    public function boot(): void
    {
        // 非生产环境开启「静默丢弃属性」检查：$guarded/$fillable 写错当场报错，
        // 避免线上出现「字段没存进去但没人发现」的资金类事故
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // 站点对外强制 https（Octane 在 nginx 反代后，需主动生成 https 链接）
        if ($this->app->isProduction() || (bool) env('APP_FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        }
    }
}

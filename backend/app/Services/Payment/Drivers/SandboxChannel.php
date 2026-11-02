<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Payment\Drivers;

use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentChannel;
use App\Services\Payment\Contracts\PaymentNotification;
use Illuminate\Support\Carbon;

/**
 * 沙箱通道（零采购：无商户号时的默认实现）。
 *
 * 不是「假支付」：异步通知照样走 HMAC-SHA256 验签 + 时间戳窗口，
 * 只是签名密钥来自 .env 而非微信平台证书。等商户号下来，新增 WechatChannel
 * 实现同一契约即可，PaymentService 与订单流程一行不用改。
 */
class SandboxChannel implements PaymentChannel
{
    public function __construct(
        private readonly string $secret,
        private readonly int $notifyTolerance = 300,
    ) {}

    public function name(): string
    {
        return 'sandbox';
    }

    public function create(Payment $payment, array $context = []): array
    {
        // 真实通道这里会返回 prepay_id / paySign 等拉起参数；沙箱给出可预测的占位值，
        // 前端据此走「确认支付」调试流程，不接触任何真实资金。
        return [
            'channel' => $this->name(),
            'payment_no' => $payment->payment_no,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'prepay_id' => 'sbx_'.$payment->payment_no,
            'sandbox' => true,
        ];
    }

    public function verifyNotify(string $rawBody, array $headers): ?PaymentNotification
    {
        $headers = array_change_key_case($headers, CASE_LOWER);

        $signature = $this->header($headers, 'x-payment-signature');
        $timestamp = $this->header($headers, 'x-payment-timestamp');

        if ($signature === '' || ! ctype_digit($timestamp)) {
            return null;
        }

        // 重放窗口：过期通知一律拒绝，否则抓到一条旧回调就能反复触发业务
        if (abs(Carbon::now()->getTimestamp() - (int) $timestamp) > $this->notifyTolerance) {
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || ! isset($payload['provider_trade_no'], $payload['amount'])) {
            return null;
        }

        return new PaymentNotification(
            providerTradeNo: (string) $payload['provider_trade_no'],
            amount: (string) $payload['amount'],
            succeeded: (bool) ($payload['succeeded'] ?? false),
            paidAt: (string) ($payload['paid_at'] ?? Carbon::now()->toIso8601String()),
            raw: $payload,
        );
    }

    /**
     * 取头字段。Symfony/Slim 风格的头表里一个名字可能对应数组（重复头），
     * 统一取第一个；直接 (string) 强转会触发 "Array to string conversion"。
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    private function header(array $headers, string $name): string
    {
        $value = $headers[$name] ?? '';

        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        return trim((string) $value);
    }

    public function refund(Payment $payment, string $amount, string $reason): array
    {
        return [
            'channel' => $this->name(),
            'refund_no' => 'sbxrf_'.$payment->payment_no,
            'amount' => $amount,
            'reason' => $reason,
            'sandbox' => true,
        ];
    }
}

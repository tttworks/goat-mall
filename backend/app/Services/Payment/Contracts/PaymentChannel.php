<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Payment\Contracts;

use App\Models\Payment;

/**
 * 支付通道契约（T5）。
 *
 * 只定义「我方需要通道做的三件事」：拉起支付、校验异步通知、发起退款。
 * 分账、冻结这些担保交易语义由 PaymentService 自己维护，通道只负责资金实际进出。
 * 这样接微信收付通还是sandbox，业务代码零改动。
 */
interface PaymentChannel
{
    public function name(): string;

    /**
     * 创建渠道侧支付单，返回前端拉起支付所需的参数。
     *
     * @return array<string, mixed>
     */
    public function create(Payment $payment, array $context = []): array;

    /**
     * 校验异步通知的签名并解析出对账要素。
     *
     * 返回 null 表示签名不合法——调用方必须直接丢弃，不能落库。
     */
    public function verifyNotify(string $rawBody, array $headers): ?PaymentNotification;

    /**
     * 发起退款。
     *
     * @return array<string, mixed> 渠道返回体（落 raw_payload 备查）
     */
    public function refund(Payment $payment, string $amount, string $reason): array;
}

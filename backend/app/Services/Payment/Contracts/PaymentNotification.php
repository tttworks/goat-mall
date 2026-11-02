<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Payment\Contracts;

/**
 * 渠道异步通知解析结果（值对象，不含业务判断）。
 *
 * 只装「渠道告诉我们的事实」：渠道单号、金额、是否成功。
 * 是否与本地支付单匹配、金额是否一致，全部由 PaymentService 判定——
 * 渠道回调一律不可信，先验签再比对，比对不过按失败处理。
 */
final class PaymentNotification
{
    public function __construct(
        public readonly string $providerTradeNo,
        public readonly string $amount,
        public readonly bool $succeeded,
        public readonly string $paidAt,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {}
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

/**
 * 可举报对象（T6 · 社群治理）。
 *
 * 只收这三类：它们都有独立的展示页和明确的归属人，后台能据此找到证据与责任人。
 * 订单纠纷不走举报——那是退款/客服流程，混进举报队列会让运营同时面对两套判定口径。
 */
enum ReportTargetType: string
{
    case Listing = 'listing';
    case Post = 'post';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Listing => '商品',
            self::Post => '帖子',
            self::User => '用户',
        };
    }
}

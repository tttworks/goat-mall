<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 *
 * Reverb WebSocket 频道鉴权（T1 骨架；用户体系由 T2 提供，此处只做身份与归属校验）。
 * 原则：出价/订单事件只广播「公开字段」，绝不广播买家手机号、地址等隐私。
 */

use Illuminate\Support\Facades\Broadcast;

// 用户私有通道：通知、私信、订单状态变更
Broadcast::channel('goat.user.{userId}', function ($user, int $userId): bool {
    return (int) ($user->user_id ?? 0) === $userId;
});

// 竞拍公开通道：允许游客订阅（出价需要登录，但看价不需要）
Broadcast::channel('goat.auction.{auctionId}', fn (): bool => true);

// 图鑑/商品详情行情推送（公开）
Broadcast::channel('goat.figure.{figureId}', fn (): bool => true);

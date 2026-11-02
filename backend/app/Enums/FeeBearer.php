<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

/**
 * 费率承担方（后台可配置，百分比可设 0 = 免费期）。
 * seller：到手价 = 货款 - 手续费；buyer：应付 = 货款 + 手续费。
 */
enum FeeBearer: string
{
    case Seller = 'seller';
    case Buyer = 'buyer';

    public function label(): string
    {
        return match ($this) {
            self::Seller => '卖家承担',
            self::Buyer => '买家承担',
        };
    }
}

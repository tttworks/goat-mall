<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Exceptions;

use DomainException;

/**
 * 交易/竞价业务拒绝（库存不足、自购自拍、低于最低加价、竞拍已结束等）。
 * HTTP 层映射 422；与 IllegalStateTransition(409) 区分：前者是「请求不合法」，后者是「内部状态非法」。
 */
class TradeException extends DomainException
{
}

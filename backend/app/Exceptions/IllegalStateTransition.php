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
 * 非法状态跃迁（契约 §"非法状态跃迁必须告警"）。
 * 用 DomainException 派生，HTTP 层由 exception handler 映射为 409，并触发告警链路。
 */
class IllegalStateTransition extends DomainException
{
}

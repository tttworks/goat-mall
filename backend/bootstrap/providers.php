<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * 应用服务提供者注册表。
 * 只登记 GOAT 自有 provider；框架 provider 由 Illuminate 自动发现（package:discover）。
 */
return [
    App\Providers\AppServiceProvider::class,
];

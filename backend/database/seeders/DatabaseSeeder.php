<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 默认 Seeder。
 *
 * 只做「上线必需且幂等」的事：没有管理员就进不了运营后台，其余业务数据一律由
 * 后台/接口产生。演示数据不进 Seeder —— 它会被 `migrate --seed` 带到生产。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
        ]);
    }
}

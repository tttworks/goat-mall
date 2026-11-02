<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 收货地址（T4）。
 *
 * 没有软删除：个人地址删除即真删；订单里已存收货快照，单据不依赖本表存活。
 *
 * @property int $address_id
 * @property int $user_id
 * @property string $receiver_name
 * @property string $receiver_phone
 * @property string $province
 * @property string $city
 * @property string|null $district
 * @property string $detail
 * @property bool $is_default
 */
class Address extends Model
{
    protected $table = 'addresses';

    protected $primaryKey = 'address_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** 省市区一行展示（小程序端地址卡片、订单快照都按这个口径拼） */
    public function region(): string
    {
        return implode('', array_filter([$this->province, $this->city, $this->district]));
    }

    public function fullAddress(): string
    {
        return $this->region().$this->detail;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'address_id' => $this->address_id,
            'receiver_name' => $this->receiver_name,
            'receiver_phone' => $this->receiver_phone,
            'province' => $this->province,
            'city' => $this->city,
            'district' => $this->district,
            'detail' => $this->detail,
            'region' => $this->region(),
            'full_address' => $this->fullAddress(),
            'is_default' => (bool) $this->is_default,
        ];
    }
}

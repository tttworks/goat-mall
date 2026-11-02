<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Trade;

use App\Exceptions\TradeException;
use App\Models\Address;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 收货地址簿（T4）。
 *
 * 只有一条不能破的规则：**默认地址每人至多一条**。
 * 库层没做部分唯一索引（跨 PG/SQLite 的方言分支不值得），因此写默认时必须
 * 「先清后设」且包在同一事务里，否则并发下会出现两条默认，发货取哪条全靠运气。
 *
 * 越权一律按 404 处理：查询恒定带 user_id 条件，不做「先取再判归属」。
 */
class AddressService
{
    /** 单人地址上限：够用且能挡住脚本刷库（一个买家同时寄往十几个地址不现实） */
    public const MAX_PER_USER = 10;

    /** @return Collection<int, Address> */
    public function list(int $userId): Collection
    {
        return Address::query()
            ->where('user_id', $userId)
            ->orderByDesc('is_default')
            ->orderByDesc('address_id')
            ->get();
    }

    /**
     * @param  array{receiver_name: string, receiver_phone: string, province: string, city: string, district?: string|null, detail: string, is_default?: bool}  $data
     */
    public function create(int $userId, array $data): Address
    {
        return DB::transaction(function () use ($userId, $data) {
            $count = Address::query()->where('user_id', $userId)->count();

            if ($count >= self::MAX_PER_USER) {
                throw new TradeException('收货地址最多保存 '.self::MAX_PER_USER.' 条，请先删除不用的');
            }

            // 第一条强制为默认：否则新用户下单时还得额外点一次「设为默认」
            $isDefault = (bool) ($data['is_default'] ?? false) || $count === 0;

            if ($isDefault) {
                $this->clearDefault($userId);
            }

            return Address::query()->create([
                'user_id' => $userId,
                'receiver_name' => $data['receiver_name'],
                'receiver_phone' => $data['receiver_phone'],
                'province' => $data['province'],
                'city' => $data['city'],
                'district' => $data['district'] ?? null,
                'detail' => $data['detail'],
                'is_default' => $isDefault,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $userId, int $addressId, array $data): Address
    {
        return DB::transaction(function () use ($userId, $addressId, $data) {
            $address = $this->findOwned($userId, $addressId);

            $fillable = ['receiver_name', 'receiver_phone', 'province', 'city', 'district', 'detail'];
            foreach ($fillable as $field) {
                if (array_key_exists($field, $data)) {
                    $address->{$field} = $data[$field];
                }
            }

            // 取消默认不在这里处理：把唯一的默认地址取消掉会让下单页无默认可选中，
            // 要换默认请改选另一条（PUT /me/addresses/{id}/default 或 is_default=true）
            if (($data['is_default'] ?? false) === true) {
                $this->clearDefault($userId);
                $address->is_default = true;
            }

            $address->save();

            return $address->refresh();
        });
    }

    /** 删除：删掉默认地址后，把最新一条顶上来，避免下次下单没有默认可选 */
    public function destroy(int $userId, int $addressId): void
    {
        DB::transaction(function () use ($userId, $addressId) {
            $address = $this->findOwned($userId, $addressId);
            $wasDefault = (bool) $address->is_default;

            $address->delete();

            if ($wasDefault) {
                $next = Address::query()
                    ->where('user_id', $userId)
                    ->orderByDesc('address_id')
                    ->first();

                if ($next !== null) {
                    $next->forceFill(['is_default' => true])->save();
                }
            }
        });
    }

    public function setDefault(int $userId, int $addressId): Address
    {
        return DB::transaction(function () use ($userId, $addressId) {
            $address = $this->findOwned($userId, $addressId);

            $this->clearDefault($userId);
            $address->forceFill(['is_default' => true])->save();

            return $address->refresh();
        });
    }

    public function defaultOf(int $userId): ?Address
    {
        return Address::query()
            ->where('user_id', $userId)
            ->orderByDesc('is_default')
            ->orderByDesc('address_id')
            ->first();
    }

    /** 下单用：必须属于本人，找不到按 404（不泄露他人地址是否存在） */
    public function findOwned(int $userId, int $addressId): Address
    {
        return Address::query()
            ->where('user_id', $userId)
            ->whereKey($addressId)
            ->firstOrFail();
    }

    private function clearDefault(int $userId): void
    {
        Address::query()
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}

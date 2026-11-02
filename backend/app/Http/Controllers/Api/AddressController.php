<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Models\Address;
use App\Services\Trade\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * 收货地址簿（T4）。user_id 一律取自登录态，路由参数只表示「操作对象」；
 * 越权（改别人的地址）在服务层按 404 处理，不返回 403——403 等于承认这条地址存在。
 */
class AddressController extends Controller
{
    public function __construct(private readonly AddressService $addresses) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->addresses->list((int) $request->user()->user_id);

        return response()->json([
            'data' => $rows->map(fn (Address $address) => $address->toPayload())->all(),
            'meta' => [
                'total' => $rows->count(),
                'max' => AddressService::MAX_PER_USER,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $address = $this->addresses->create((int) $request->user()->user_id, $validated);

        return response()->json(['data' => $address->toPayload()], 201);
    }

    public function update(Request $request, int $addressId): JsonResponse
    {
        $validated = $request->validate($this->rules(true));

        $address = $this->addresses->update((int) $request->user()->user_id, $addressId, $validated);

        return response()->json(['data' => $address->toPayload()]);
    }

    public function destroy(Request $request, int $addressId): JsonResponse
    {
        $this->addresses->destroy((int) $request->user()->user_id, $addressId);

        // 删除后把最新的默认地址带回去：前端不必为了「哪个是默认」再打一次列表
        $default = $this->addresses->defaultOf((int) $request->user()->user_id);

        return response()->json(['data' => $default?->toPayload()]);
    }

    public function setDefault(Request $request, int $addressId): JsonResponse
    {
        $address = $this->addresses->setDefault((int) $request->user()->user_id, $addressId);

        return response()->json(['data' => $address->toPayload()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'receiver_name' => [$presence, 'string', 'max:32'],
            // 国内手机号：发货短信、快递异常件联系全靠它，不做宽松匹配
            'receiver_phone' => [$presence, 'string', 'regex:/^1[3-9]\d{9}$/'],
            'province' => [$presence, 'string', 'max:32'],
            'city' => [$presence, 'string', 'max:32'],
            'district' => ['nullable', 'string', 'max:32'],
            'detail' => [$presence, 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean', Rule::in([true, false, 0, 1, '0', '1'])],
        ];
    }
}

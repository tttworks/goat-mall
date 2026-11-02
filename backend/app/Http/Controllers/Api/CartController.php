<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Http\Requests\CartStoreRequest;
use App\Services\Social\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 购物车（T6）。所有操作限定当前登录用户，user_id 不接受入参。
 */
class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->cart->items((int) $request->user()->user_id),
        ]);
    }

    /** 幂等：首次 201，已在车内返回 200 */
    public function store(CartStoreRequest $request): JsonResponse
    {
        $result = $this->cart->add(
            (int) $request->user()->user_id,
            (int) $request->validated('listing_id')
        );

        return response()->json([
            'data' => ['listing_id' => (int) $result['item']->listing_id],
        ], $result['created'] ? 201 : 200);
    }

    public function destroy(Request $request, int $listing): JsonResponse
    {
        $removed = $this->cart->remove((int) $request->user()->user_id, $listing);

        return response()->json(['data' => ['listing_id' => $listing, 'removed' => $removed]]);
    }

    public function clear(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['cleared' => $this->cart->clear((int) $request->user()->user_id)],
        ]);
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Services\Catalog\MediaPresigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 卖家图片上传（T4 卖家端）。
 *
 * 后台媒体上传（/admin/media）只对运营开放，卖家端此前没有任何上传入口，
 * 而 ListingService::submit 又硬性要求「必须有 cover_key」——结果就是卖家永远提交不了审核。
 * 这里复用同一个 MediaPresigner，但目录锁死 listings，避免卖家往图鑑/头像目录写文件。
 *
 * 只做服务端代传（multipart）：C 端运行在 uni-app 里，直传需要额外的 PUT 能力与 CORS 配合，
 * 图片过 PHP 的带宽代价在卖家端（单张、低频）可以接受。
 */
class MediaController extends Controller
{
    public function __construct(private readonly MediaPresigner $presigner) {}

    public function storeListingImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'image', 'max:10240'],
        ]);

        $key = $this->presigner->store($validated['file'], 'listings');

        return response()->json([
            'data' => [
                'key' => $key,
                'url' => $this->presigner->url($key),
            ],
        ], 201);
    }
}

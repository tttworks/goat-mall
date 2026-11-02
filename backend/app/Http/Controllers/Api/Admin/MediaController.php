<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Catalog\MediaPresignRequest;
use App\Services\Catalog\MediaPresigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * 后台媒体上传（T3，仅 admin）。
 *
 * 两条路：
 *   POST /admin/media/presign → 拿预签名直传 UFile（生产首选，图片不过 PHP）
 *   POST /admin/media         → 服务端代传（本地开发兜底、或前端环境拿不到 CORS 时的备选）
 * 两者返回统一结构 {key, url}，编辑框只需回填 key。
 */
class MediaController extends Controller
{
    public function __construct(private readonly MediaPresigner $presigner) {}

    public function presign(MediaPresignRequest $request): JsonResponse
    {
        $folder = $request->string('folder')->toString() ?: 'figures';

        $credential = $this->presigner->presign(
            $request->string('filename')->toString(),
            $request->string('mime')->toString(),
            $folder
        );

        return response()->json([
            'data' => $credential + ['preview_url' => $this->presigner->url($credential['key'])],
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // 10MB 上限：UFile 上传带宽与 Octane package_max_length 都要留余量
            'file' => ['required', 'file', 'image', 'max:10240'],
            'folder' => ['nullable', 'string', Rule::in(['figures', 'listings', 'posts', 'avatars'])],
        ]);

        $key = $this->presigner->store($validated['file'], $validated['folder'] ?? 'figures');

        return response()->json([
            'data' => [
                'key' => $key,
                'url' => $this->presigner->url($key),
            ],
        ], 201);
    }
}

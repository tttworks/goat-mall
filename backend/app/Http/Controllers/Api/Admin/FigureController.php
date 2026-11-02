<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api\Admin;

use App\Enums\FigureCategory;
use App\Enums\FigureStatus;
use App\Http\Requests\FigureStoreRequest;
use App\Http\Requests\FigureUpdateRequest;
use App\Http\Resources\FigureResource;
use App\Models\Figure;
use App\Services\Catalog\FigureSearchService;
use App\Services\Catalog\FigureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * 图鑑管理端（T3，仅 admin）。CRUD + 发布/归档 + 媒体上传入口。
 *
 * 权限：路由层已挂 `role:admin`，此处不再重复判断；
 * FormRequest::authorize() 仍保留一道（防止未来有人把路由挪到别的分组而漏掉中间件）。
 */
class FigureController extends Controller
{
    public function __construct(
        private readonly FigureService $service,
        private readonly FigureSearchService $search,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(FigureStatus::class)],
            'maker' => ['nullable', 'string', 'max:120'],
            'series' => ['nullable', 'string', 'max:120'],
            'scale' => ['nullable', 'string', 'max:24'],
            'category' => ['nullable', Rule::enum(FigureCategory::class)],
            'year' => ['nullable', 'integer', 'between:1950,2100'],
            'sort' => ['nullable', 'string', 'in:latest,year_desc,year_asc,price_low_asc,popular'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->search->paginate($validated, true, (int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => FigureResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'categories' => FigureCategory::options(),
                'statuses' => array_map(
                    static fn (FigureStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                    FigureStatus::cases()
                ),
            ],
        ]);
    }

    public function store(FigureStoreRequest $request): JsonResponse
    {
        $figure = $this->service->create($request->validated(), $request->user());

        return response()->json(
            ['data' => FigureResource::make($figure->load('sources'), true)],
            201
        );
    }

    public function show(int $figure): JsonResponse
    {
        $record = Figure::query()->with('sources')->withCount('listings')->findOrFail($figure);

        return response()->json(['data' => FigureResource::make($record, true)]);
    }

    public function update(FigureUpdateRequest $request, int $figure): JsonResponse
    {
        $record = Figure::query()->findOrFail($figure);
        $updated = $this->service->update($record, $request->validated());

        return response()->json(['data' => FigureResource::make($updated->load('sources'), true)]);
    }

    /** 发布 / 归档 / 退回草稿：单一入口，避免「发布」与「编辑」两条路各写一套 published_at 逻辑 */
    public function updateStatus(Request $request, int $figure): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(FigureStatus::class)],
        ]);

        $record = $this->service->changeStatus(
            Figure::query()->findOrFail($figure),
            FigureStatus::from($validated['status'])
        );

        return response()->json(['data' => FigureResource::make($record, true)]);
    }

    public function destroy(int $figure): JsonResponse
    {
        $this->service->delete(Figure::query()->findOrFail($figure));

        return response()->json(null, 204);
    }
}

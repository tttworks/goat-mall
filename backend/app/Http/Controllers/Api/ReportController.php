<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Enums\ReportReason;
use App\Enums\ReportTargetType;
use App\Http\Requests\ReportStoreRequest;
use App\Services\Community\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * 举报提交（T6 · 社群治理）。
 *
 * 只做「提交」：判定与处置在 Admin\ReportController。
 * 提交成功不返回工单细节（避免用户拿举报当查询接口去探测他人内容是否存在）——
 * 目标不存在时服务层已按 404 拒绝，这里的返回只有状态与提示。
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function store(ReportStoreRequest $request): JsonResponse
    {
        $report = $this->reports->submit(
            (int) $request->user()->user_id,
            ReportTargetType::from((string) $request->validated('target_type')),
            (int) $request->validated('target_id'),
            ReportReason::from((string) $request->validated('reason')),
            $request->validated('detail'),
        );

        return response()->json([
            'data' => [
                'report_id' => (int) $report->report_id,
                'status' => $report->status->value,
                'status_label' => $report->status->label(),
            ],
            'message' => '已收到举报，平台会尽快核实处理',
        ], 201);
    }
}

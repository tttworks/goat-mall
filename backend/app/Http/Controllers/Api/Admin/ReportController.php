<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ReportStatus;
use App\Services\Community\ReportService;
use App\Services\Notify\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * 举报队列（T6，仅 admin）。
 *
 * 队列默认只列 pending，按先到先判排序；结论只有「成立 / 不成立」两种，
 * 处置动作（下架商品、撤回帖子、封禁账号）仍走各自的管理入口——
 * 举报只负责「有没有问题」的判定，越权改内容状态会让责任链断掉。
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ReportStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = $this->reports->paginate(
            $validated['status'] ?? null,
            (int) ($validated['per_page'] ?? 20)
        );

        return response()->json([
            'data' => $this->reports->payloads($page->items()),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function handle(Request $request, int $report): JsonResponse
    {
        $validated = $request->validate([
            'result' => ['required', Rule::in([ReportStatus::Handled->value, ReportStatus::Dismissed->value])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $this->reports->handle(
            $report,
            (int) $request->user()->user_id,
            ReportStatus::from((string) $validated['result']),
            $validated['note'] ?? null
        );

        $handled = $updated->status === ReportStatus::Handled;

        // 回告举报人：带日期的去重键，同一工单被重新举报并再次判定时仍能收到新结论
        $this->notifications->push(
            (int) $updated->reporter_id,
            'report.handled',
            $handled ? '你的举报已处理' : '你的举报未通过核实',
            $handled
                ? ($updated->handle_note ?? '平台已核实并处理，感谢你的反馈')
                : ($updated->handle_note ?? '平台核实后未发现违规，如有新证据可再次举报'),
            ['report_id' => (int) $updated->report_id, 'target_type' => $updated->target_type->value, 'target_id' => (int) $updated->target_id],
            'report:'.$updated->report_id.':'.$updated->status->value.':'.($updated->handled_at?->timestamp ?? 0)
        );

        return response()->json(['data' => $this->reports->payloads([$updated])[0]]);
    }
}

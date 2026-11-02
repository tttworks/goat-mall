<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuctionStatus;
use App\Exceptions\TradeException;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * 后台竞价管理（T8）。
 *
 * 撤场只允许「无人出价」的场次：一旦有出价，背后就挂着竞价保证金与出价者预期，
 * 强制撤销要连带解冻保证金、通知所有出价人，属于「资金事件」而不是「状态改动」，
 * 因此这里直接拒绝并提示，宁可让运营走线下沟通，也不留一个能把钱搞乱的后门。
 */
class AuctionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_column(AuctionStatus::cases(), 'value'))],
            'keyword' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = Auction::query()
            ->with(['listing.figure'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['keyword'] ?? null, function ($query, $keyword) {
                $query->whereHas('listing', fn ($q) => $q->where('title', 'like', '%'.$keyword.'%'));
            })
            // 进行中的排前面：运营关心的是「现在正在拍什么」
            ->orderByRaw("case status when 'active' then 1 when 'scheduled' then 2 when 'ended' then 3 else 4 end")
            ->orderBy('end_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Auction $auction) => $this->row($auction))->all(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'statuses' => collect(AuctionStatus::cases())->map(fn (AuctionStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])->all(),
            ],
        ]);
    }

    public function show(int $auctionId): JsonResponse
    {
        $auction = Auction::query()->with(['listing.figure', 'bids'])->whereKey($auctionId)->firstOrFail();

        // 一次性取昵称，避免 20 条出价查 20 次用户（也不在模型上加 relation，只在后台用）
        $names = User::query()
            ->whereIn('user_id', $auction->bids->pluck('bidder_id')->unique()->all())
            ->pluck('nickname', 'user_id');

        return response()->json([
            'data' => $this->row($auction) + [
                'bids' => $auction->bids->sortByDesc('bid_id')->values()->map(function ($bid) use ($names) {
                    $nickname = (string) ($names[$bid->bidder_id] ?? '');

                    return [
                        'bid_id' => $bid->bid_id,
                        'amount' => (string) $bid->amount,
                        // 后台也脱敏：运营判断「是不是同一个号在抬价」看 user_id 即可，不需要昵称全称
                        'bidder' => $nickname === '' ? '用户'.substr((string) $bid->bidder_id, -4) : mb_substr($nickname, 0, 1).'**',
                        'bidder_id' => $bid->bidder_id,
                        'created_at' => ($bid->placed_at ?? $bid->created_at)?->toIso8601String(),
                    ];
                })->all(),
            ],
        ]);
    }

    public function cancel(Request $request, int $auctionId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:2', 'max:200'],
        ]);

        $auction = DB::transaction(function () use ($auctionId, $request, $validated) {
            /** @var Auction $auction */
            $auction = Auction::query()->whereKey($auctionId)->lockForUpdate()->firstOrFail();

            if (! in_array($auction->status, [AuctionStatus::Scheduled, AuctionStatus::Active], true)) {
                throw new TradeException('只有未开始或进行中的场次可以撤销');
            }

            if ((int) $auction->bid_count > 0) {
                throw new TradeException('该场次已有出价，不能直接撤销（涉及出价人保证金，请走线下处理）');
            }

            // 只撤场次、不回收商品：撤拍后卖家仍可用一口价卖出，比强制下架更符合预期。
            // auctions 表没有 remark 列，撤销原因落在审计日志里（后台页面按 auction_id 检索）。
            $auction->transitTo(AuctionStatus::Cancelled);

            Log::channel('goat.trade')->warning('auction.admin_cancelled', [
                'auction_id' => $auction->auction_id,
                'admin_id' => $request->user()->user_id,
                'reason' => $validated['reason'],
            ]);

            return $auction;
        }, 3);

        return response()->json(['data' => $this->row($auction->refresh())]);
    }

    /** @return array<string, mixed> */
    private function row(Auction $auction): array
    {
        return [
            'auction_id' => $auction->auction_id,
            'listing_id' => $auction->listing_id,
            'title' => $auction->listing?->title,
            'cover_url' => $auction->listing?->coverUrl(),
            'figure_id' => $auction->listing?->figure_id,
            'figure_name' => $auction->listing?->figure?->name,
            'status' => $auction->status->value,
            'status_label' => $auction->status->label(),
            'start_price' => (string) $auction->start_price,
            'current_price' => (string) $auction->current_price,
            'bid_count' => (int) $auction->bid_count,
            'min_increment' => (string) $auction->min_increment,
            'start_at' => $auction->start_at?->toIso8601String(),
            'end_at' => $auction->end_at?->toIso8601String(),
            'created_at' => $auction->created_at?->toIso8601String(),
        ];
    }
}

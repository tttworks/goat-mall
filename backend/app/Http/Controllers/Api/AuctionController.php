<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Services\Trade\BiddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 竞拍浏览 + 出价（T4）。出价必须带幂等键，防客户端重试重复抬价。
 */
class AuctionController extends Controller
{
    public function __construct(private readonly BiddingService $bidding) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,scheduled,active,ended,settled,failed,cancelled'],
            'figure_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', 'in:ending_soon,latest,price_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $query = Auction::query()
            ->with('listing.figure')
            ->where('status', $validated['status'] ?? AuctionStatus::Active->value);

        if (! empty($validated['figure_id'])) {
            $query->where('figure_id', $validated['figure_id']);
        }

        match ($validated['sort'] ?? 'ending_soon') {
            'latest' => $query->orderByDesc('auction_id'),
            'price_desc' => $query->orderByDesc('current_price'),
            default => $query->orderBy('end_at'),
        };

        $page = $query->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Auction $auction) => $this->payload($auction)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(int $auctionId): JsonResponse
    {
        $auction = Auction::query()->with('listing.figure')->whereKey($auctionId)->firstOrFail();

        return response()->json([
            'data' => $this->payload($auction) + [
                'next_min_bid' => $auction->nextMinBid(),
                'anti_snipe_seconds' => $auction->anti_snipe_seconds,
                'extension_count' => $auction->extension_count,
                'max_extensions' => $auction->max_extensions,
                'bids' => $auction->bids()->orderByDesc('bid_id')->limit(20)->get()
                    ->map(fn ($bid) => [
                        'bid_id' => $bid->bid_id,
                        'amount' => (string) $bid->amount,
                        'status' => $bid->status,
                        'placed_at' => $bid->placed_at?->toIso8601String(),
                    ]),
            ],
        ]);
    }

    public function bid(Request $request, int $auctionId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $key = (string) ($request->header('Idempotency-Key') ?: ($validated['idempotency_key'] ?? ''));
        if ($key === '') {
            return response()->json(['message' => '缺少幂等键（Idempotency-Key）'], 422);
        }

        $result = $this->bidding->placeBid(
            $auctionId,
            (int) $request->user()->user_id,
            // 用原始字符串，避免 float 往返丢精度
            (string) $request->input('amount'),
            $key
        );

        return response()->json([
            'data' => [
                'bid_id' => $result['bid']->bid_id,
                'amount' => (string) $result['bid']->amount,
                'status' => $result['bid']->status,
            ],
            'auction' => $this->payload($result['auction']),
            'extended' => $result['extended'],
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    /**
     * 竞拍出参：除价格与时间外，必须带「卖的是什么」。
     * 只给 listing_id 的话，C 端列表页只能凭空渲染一行数字（无标题、无封面），等于空白页。
     *
     * @return array<string, mixed>
     */
    private function payload(Auction $auction): array
    {
        $listing = $auction->listing;
        $figure = $listing?->figure;

        return [
            'auction_id' => $auction->auction_id,
            'listing_id' => $auction->listing_id,
            'figure_id' => $auction->figure_id,
            'title' => $listing?->title,
            'cover_url' => $listing?->coverUrl(),
            // 列名是 condition_enum（见 create_listings_table），出参字段名统一为 condition
            'condition' => $listing?->condition_enum,
            'listing_status' => $listing?->status->value,
            'figure_name' => $figure?->name,
            'status' => $auction->status->value,
            'status_label' => $auction->status->label(),
            'start_price' => (string) $auction->start_price,
            'current_price' => (string) $auction->current_price,
            'min_increment' => (string) $auction->min_increment,
            'bid_count' => $auction->bid_count,
            'currency' => $auction->currency,
            'start_at' => $auction->start_at?->toIso8601String(),
            'end_at' => $auction->end_at?->toIso8601String(),
        ];
    }
}

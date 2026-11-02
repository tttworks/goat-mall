<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Http\Requests\AuctionStoreRequest;
use App\Models\Auction;
use App\Models\Listing;
use App\Services\Trade\AuctionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 卖家开拍（T4）。竞拍是「在售商品」的另一种售卖方式，
 * 因此开拍前必须先有 active 状态的商品，且同一商品不允许并存的未结束场次。
 */
class SellerAuctionController extends Controller
{
    public function __construct(private readonly AuctionService $auctions) {}

    public function store(AuctionStoreRequest $request, int $listingId): JsonResponse
    {
        $listing = Listing::query()
            ->where('seller_id', (int) $request->user()->user_id)
            ->whereKey($listingId)
            ->firstOrFail();

        $auction = $this->auctions->create($request->user(), $listing, $request->validated());

        return response()->json(['data' => $this->payload($auction)], 201);
    }

    /** 撤销场次（无人出价才允许，服务层会挡掉已有出价的场次） */
    public function cancel(Request $request, int $auctionId): JsonResponse
    {
        $auction = Auction::query()
            ->where('seller_id', (int) $request->user()->user_id)
            ->whereKey($auctionId)
            ->firstOrFail();

        $reason = (string) $request->input('reason', '');
        $auction = $this->auctions->cancel($request->user(), $auction, $reason);

        return response()->json(['data' => $this->payload($auction)]);
    }

    /** @return array<string, mixed> */
    private function payload(Auction $auction): array
    {
        return [
            'auction_id' => $auction->auction_id,
            'listing_id' => $auction->listing_id,
            'status' => $auction->status->value,
            'status_label' => $auction->status->label(),
            'start_price' => (string) $auction->start_price,
            'reserve_price' => $auction->reserve_price === null ? null : (string) $auction->reserve_price,
            'min_increment' => (string) $auction->min_increment,
            'current_price' => (string) $auction->current_price,
            'deposit_amount' => (string) $auction->deposit_amount,
            'start_at' => $auction->start_at?->toIso8601String(),
            'end_at' => $auction->end_at?->toIso8601String(),
        ];
    }
}

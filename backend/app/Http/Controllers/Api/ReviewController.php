<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Controllers\Api;

use App\Http\Requests\ReviewReplyRequest;
use App\Http\Requests\ReviewStoreRequest;
use App\Models\Review;
use App\Services\Notify\NotificationService;
use App\Services\Social\FollowService;
use App\Services\Social\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 交易评价（T6）：买家评价、卖家回复、店铺评价墙（公开）。
 *
 * 通知与评价分两个服务：评价事务先落，通知失败不影响评价结果（通知只做 dedupe 幂等）。
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly NotificationService $notifications,
        private readonly FollowService $follows,
    ) {}

    public function store(ReviewStoreRequest $request, int $order): JsonResponse
    {
        $review = $this->reviews->create(
            (int) $request->user()->user_id,
            $order,
            [
                'rating' => (int) $request->validated('rating'),
                'content' => $request->validated('content'),
                'images' => (array) ($request->validated('images') ?? []),
            ]
        );

        $this->notifications->push(
            (int) $review->seller_id,
            'review.received',
            '收到新的买家评价',
            sprintf('%d 星评价，可在店铺评价里回复', $review->rating),
            ['review_id' => $review->review_id, 'order_id' => (int) $review->order_id],
            'review:'.$review->review_id.':received'
        );

        return response()->json(['data' => $this->reviews->payload($review)], 201);
    }

    public function reply(ReviewReplyRequest $request, int $review): JsonResponse
    {
        $updated = $this->reviews->reply(
            (int) $request->user()->user_id,
            $review,
            (string) $request->validated('content')
        );

        $this->notifications->push(
            (int) $updated->buyer_id,
            'review.replied',
            '卖家回复了你的评价',
            mb_substr((string) $updated->seller_reply, 0, 100),
            ['review_id' => $updated->review_id, 'order_id' => (int) $updated->order_id],
            'review:'.$updated->review_id.':replied'
        );

        return response()->json(['data' => $this->reviews->payload($updated)]);
    }

    /** 店铺评价墙（公开）：分页 + 评分概览，未登录也能看 */
    public function sellerIndex(Request $request, int $seller): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = $this->reviews->forSeller($seller, (int) ($validated['per_page'] ?? 20));

        $viewer = $request->user();

        return response()->json([
            'data' => array_map(
                fn (Review $review) => $this->reviews->payload($review),
                $page->items()
            ),
            'summary' => $this->reviews->summary($seller),
            'following' => $viewer === null
                ? null
                : $this->follows->isFollowing((int) $viewer->user_id, $seller),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }
}

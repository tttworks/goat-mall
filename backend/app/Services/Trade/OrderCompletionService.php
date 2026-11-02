<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Trade;

use App\Models\Order;
use App\Services\Market\QuoteService;
use App\Services\Notify\NotificationService;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Carbon;

/**
 * 确认收货的完整编排（T4）：状态机推进 → 担保资金结算 → 成交价回写行情 → 通知。
 *
 * 为什么单独抽一层：买家手动确认与「超时自动确认」必须走同一条路径。
 * 这套编排原先只写在 OrderController::confirm 里，调度任务照抄一遍就必然漂移——
 * 最坏的漂移是「状态到了 completed、钱没结算」，只能人工对账。
 */
class OrderCompletionService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly QuoteService $quotes,
        private readonly NotificationService $notifications,
    ) {}

    public function confirmByBuyer(int $buyerId, int $orderId): Order
    {
        return $this->settle($this->orders->confirmReceipt($buyerId, $orderId), false);
    }

    /**
     * 系统代确认（调度）。返回 null 表示订单已不在可确认状态（已被买家确认 / 已进入退款）。
     */
    public function confirmByTimeout(Order $order, int $afterDays): ?Order
    {
        $confirmed = $this->orders->confirmReceiptByTimeout($order);

        if ($confirmed === null) {
            return null;
        }

        $settled = $this->settle($confirmed, true);

        // 买家要被告知「钱已经放给卖家了」：这是售后时效的起点，不能只静默改状态
        $this->notifications->push(
            (int) $settled->buyer_id,
            'order.completed',
            '订单已自动确认收货',
            sprintf(
                '发货后 %d 天未确认收货，系统已代你确认并释放担保款；如有问题请尽快在订单页申请售后',
                $afterDays
            ),
            ['order_id' => (int) $settled->order_id, 'order_no' => $settled->order_no],
            'order:'.$settled->order_id.':auto-completed'
        );

        return $settled;
    }

    /**
     * 付款后超时未发货：催办买卖双方（每天最多一次）。
     *
     * 只提醒不动资金——自动退款必须有人审，否则「已线下发货但没填单号」的卖家会货钱两空。
     * 返回本次扫描到的订单数（不含是否命中当日去重）。
     */
    public function nudgeUnshipped(int $days, int $limit = 200): int
    {
        $today = Carbon::now()->toDateString();
        $nudged = 0;

        foreach ($this->orders->paidUnshippedBefore($days, $limit) as $order) {
            // 去重键带日期：一天最多催一次；发货或退款后不再满足扫描条件，催办自然停止
            $dedupe = 'order:'.$order->order_id.':unshipped:'.$today;

            $this->notifications->push(
                (int) $order->buyer_id,
                'order.unshipped',
                '卖家尚未发货',
                sprintf('订单 %s 付款已超过 %d 天仍未发货，可联系卖家或在订单页申请退款', $order->order_no, $days),
                ['order_id' => (int) $order->order_id, 'order_no' => $order->order_no],
                $dedupe.':buyer'
            );

            $this->notifications->push(
                (int) $order->seller_id,
                'order.unshipped',
                '你有订单待发货',
                sprintf('订单 %s 付款已超过 %d 天，请尽快发货或与买家沟通', $order->order_no, $days),
                ['order_id' => (int) $order->order_id, 'order_no' => $order->order_no],
                $dedupe.':seller'
            );

            $nudged++;
        }

        return $nudged;
    }

    private function settle(Order $order, bool $auto): Order
    {
        // 订单状态与资金结算分属两个服务：这里做编排，谁失败谁回滚自己的事务
        if ((bool) config('goat.payment.escrow.auto_settle_on_confirm', true)) {
            $this->payments->settleByOrder((int) $order->order_id);
        }

        // 成交价回写行情：recordFromOrder 内部吞异常，行情写失败不影响「已确认收货」这个事实
        $this->quotes->recordFromOrder($order->load('items'));

        $this->notifications->push(
            (int) $order->seller_id,
            'order.completed',
            $auto ? '订单超时自动确认收货' : '买家已确认收货',
            sprintf('订单 %s 已完成，货款已结算', $order->order_no),
            ['order_id' => (int) $order->order_id],
            // 与手动确认共用同一个去重键：同一张订单只该有一条「已完成」通知
            'order:'.$order->order_id.':completed'
        );

        return $order;
    }
}

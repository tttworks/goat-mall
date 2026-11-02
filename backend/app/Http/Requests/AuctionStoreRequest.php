<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 卖家开拍（T4）。防狙击参数允许不传 → 由 AuctionService 取业务配置默认值，
 * 这里只限范围（上限 3600s，防止误填成一天导致「永远在顺延窗口」）。
 */
class AuctionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'start_price' => ['required', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'reserve_price' => ['nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'min_increment' => ['nullable', 'string', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'deposit_amount' => ['nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'anti_snipe_seconds' => ['nullable', 'integer', 'between:0,3600'],
            'extension_seconds' => ['nullable', 'integer', 'between:0,3600'],
            'max_extensions' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}

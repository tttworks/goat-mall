<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 卖家编辑商品（T4）。全部 sometimes：后台表单常只提交变化的字段，
 * 全量覆盖语义会把未提交字段清空（价格被清成 0 就是事故）。
 */
class ListingUpdateRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'figure_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'condition' => ['sometimes', Rule::in(['new', 'like_new', 'used_good', 'used_fair', 'defective', 'for_parts'])],
            'price' => ['sometimes', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            // 已上架商品也允许改运费：只影响改动之后的新订单
            'shipping_fee' => ['sometimes', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            // 已上架商品允许改库存（含设为 0 表示暂不接单）
            'stock' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'cover_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cover_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'gallery_keys' => ['sometimes', 'nullable', 'array', 'max:20'],
            'gallery_keys.*' => ['string', 'max:255'],
            'gallery_urls' => ['sometimes', 'nullable', 'array', 'max:20'],
            'gallery_urls.*' => ['string', 'max:500'],
        ];
    }
}

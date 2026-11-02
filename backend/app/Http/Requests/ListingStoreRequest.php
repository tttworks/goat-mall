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
 * 卖家上架（T4）。校验只做形态；「店铺能否上架、图鑑能否绑定、价格是否合法」由 ListingService 判定，
 * 因为这三条依赖 DB 状态，不是入参形态问题。
 */
class ListingStoreRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'figure_id' => ['nullable', 'integer', 'min:1'],
            'condition' => ['nullable', Rule::in(['new', 'like_new', 'used_good', 'used_fair', 'defective', 'for_parts'])],
            // 金额用字符串传，避免 JS 浮点污染：10.10 存成 10.099999 的经典坑
            'price' => ['required', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            // 运费：不填即包邮；下单时按此值计入应付，客户端无权改
            'shipping_fee' => ['nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'stock' => ['nullable', 'integer', 'min:1', 'max:999'],
            'cover_key' => ['nullable', 'string', 'max:255'],
            'cover_url' => ['nullable', 'string', 'max:500'],
            'gallery_keys' => ['nullable', 'array', 'max:20'],
            'gallery_keys.*' => ['string', 'max:255'],
            'gallery_urls' => ['nullable', 'array', 'max:20'],
            'gallery_urls.*' => ['string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.regex' => '价格格式非法（整数部分最多 8 位，最多两位小数）',
            'shipping_fee.regex' => '运费格式非法（整数部分最多 8 位，最多两位小数）',
            'condition.in' => '品相取值不合法',
        ];
    }
}

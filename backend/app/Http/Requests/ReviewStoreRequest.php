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
 * 买家评价（T6）。评分必填 1~5；内容与图片至少给一个（服务层再校验一次）。
 */
class ReviewStoreRequest extends FormRequest
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
            'rating' => ['required', 'integer', 'between:1,5'],
            'content' => ['nullable', 'string', 'max:500'],
            // 图片只收对象 key；最多 9 张与图鑑/商品画廊口径一致
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.between' => '评分必须在 1~5 之间',
            'images.max' => '最多上传 9 张图片',
        ];
    }
}

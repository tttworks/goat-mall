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
 * 发布社群帖（T6）。内容与图片的数量上限在服务层按配置再校验一次：
 * FormRequest 只挡住明显越界，运营调小上限时不必改代码。
 */
class PostStoreRequest extends FormRequest
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
            'content' => ['nullable', 'string', 'max:1000'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string', 'max:255'],
        ];
    }
}

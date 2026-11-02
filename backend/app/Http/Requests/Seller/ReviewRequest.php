<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 店铺审核入参（T2，仅 admin）。
 * 驳回/封禁必须给理由：理由是卖家申诉与客服沟通的唯一依据。
 */
class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approve', 'reject', 'suspend'])],
            'reason' => ['nullable', 'string', 'max:255', 'required_unless:decision,approve'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => '请选择审核结果',
            'reason.required_unless' => '驳回或封禁必须填写原因',
        ];
    }
}

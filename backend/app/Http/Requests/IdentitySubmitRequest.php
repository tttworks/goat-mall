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
 * 实名核身提交（T2）。证件号仅在本请求生命周期内以明文存在，落库前加密 + HMAC。
 */
class IdentitySubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'real_name' => ['required', 'string', 'min:2', 'max:32'],
            'id_number' => ['required', 'string', 'min:5', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'real_name.required' => '请填写真实姓名',
            'id_number.required' => '请填写证件号',
        ];
    }
}

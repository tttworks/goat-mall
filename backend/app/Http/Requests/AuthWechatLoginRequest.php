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
 * 微信小程序登录入参（T2）：前端 `wx.login()` 拿到的 code，服务端换 openid。
 * 注意：**不接受前端直传 openid**（否则可伪造任意用户身份）。
 */
class AuthWechatLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:8', 'max:128'],
            'nickname' => ['nullable', 'string', 'min:1', 'max:24'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => '缺少微信授权码',
        ];
    }
}

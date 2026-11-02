<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Apple 登录入参（T2）：iOS 端 `ASAuthorizationAppleIDCredential.identityToken`（JWT）。
 * 服务端必须验签，见 App\Services\Auth\AppleTokenVerifier。
 */
class AppleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identity_token' => ['required', 'string', 'max:4096'],
            'nickname' => ['nullable', 'string', 'min:1', 'max:24'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'identity_token.required' => '缺少 Apple 身份令牌',
        ];
    }
}

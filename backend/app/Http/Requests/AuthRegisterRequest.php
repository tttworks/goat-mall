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
 * 注册入参（T2）。
 *
 * 规则要点：
 *  - 用户名与邮箱**至少给一个**（四通道里的两个本地通道），由 withValidator 交叉校验；
 *  - 用户名限制 `alpha_dash`：避免与邮箱/手机号形态混淆，也方便后续 @提及；
 *  - 密码 8~64 位且必须 confirmed：前端两次输入一致，减少客服工单。
 */
class AuthRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $min = (int) config('goat.auth.password.min', 8);

        return [
            'username' => ['nullable', 'string', 'min:3', 'max:32', 'alpha_dash'],
            'email' => ['nullable', 'string', 'email', 'max:120'],
            'password' => ['required', 'string', "min:{$min}", 'max:64', 'confirmed'],
            'nickname' => ['nullable', 'string', 'min:2', 'max:24'],
            'locale' => ['nullable', 'string', 'in:zh_CN,zh_TW,en,ja'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (blank($this->input('username')) && blank($this->input('email'))) {
                $validator->errors()->add('username', '用户名与邮箱至少填写一个');
            }
        });
    }

    public function messages(): array
    {
        return [
            'username.alpha_dash' => '用户名只能包含字母、数字、下划线和短横线',
            'password.confirmed' => '两次输入的密码不一致',
            'password.min' => '密码至少 :min 位',
        ];
    }
}

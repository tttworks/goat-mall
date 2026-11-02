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
 * 开店申请入参（T2）。
 *
 * 一次请求同时完成「开店资料 + 实名资料」：合同要求"开店即触发实名"，
 * 拆成两步会让用户在两个页面间来回，且容易出现"开了店没实名"的空档。
 * `agree_terms` 为必勾：卖家协议是纠纷处理依据，必须留证。
 */
class SellerApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shop_name' => ['required', 'string', 'min:2', 'max:48'],
            'shop_intro' => ['nullable', 'string', 'max:500'],
            'shop_logo_key' => ['nullable', 'string', 'max:255'],

            'real_name' => ['required', 'string', 'min:2', 'max:32'],
            'id_number' => ['required', 'string', 'min:5', 'max:32'],

            'agree_terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'shop_name.required' => '请填写店铺名称',
            'real_name.required' => '开店需提交真实姓名用于实名核验',
            'id_number.required' => '开店需提交证件号用于实名核验',
            'agree_terms.accepted' => '请先阅读并同意卖家协议',
        ];
    }
}

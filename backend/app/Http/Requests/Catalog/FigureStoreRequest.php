<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Requests\Catalog;

use App\Enums\FigureCategory;
use App\Enums\FigureStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 图鑑条目录入（T3，仅 admin）。
 *
 * 校验只做「形态」校验（类型/长度/枚举），不做「语义」校验（如封面是否本站 key），
 * 语义校验放在 FigureService，因为采集侧 T9 走的是服务层而非 HTTP，两处必须共用一套规则。
 */
class FigureStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'name_ja' => ['nullable', 'string', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'aliases' => ['nullable', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:60'],

            'maker' => ['nullable', 'string', 'max:120'],
            'series' => ['nullable', 'string', 'max:120'],
            // 比例形态不固定（1/7、1/4、非比例、Nendoroid），故只限长度不限定格式
            'scale' => ['nullable', 'string', 'max:24'],
            'category' => ['nullable', Rule::enum(FigureCategory::class)],
            'release_date' => ['nullable', 'date', 'after_or_equal:1950-01-01', 'before_or_equal:2100-12-31'],

            'external_ids' => ['nullable', 'array'],
            'external_ids.*' => ['string', 'max:64'],

            'specs' => ['nullable', 'array'],
            'specs.*' => ['nullable'],

            // 封面/画廊：允许传 key 或完整 URL，由服务层统一归一化为 key
            'cover_key' => ['nullable', 'string', 'max:255'],
            'cover_url' => ['nullable', 'string', 'max:500'],
            'gallery_keys' => ['nullable', 'array', 'max:20'],
            'gallery_keys.*' => ['string', 'max:255'],
            'gallery_urls' => ['nullable', 'array', 'max:20'],
            'gallery_urls.*' => ['string', 'max:500'],

            'price_low' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-zA-Z0-9\-_]+$/'],
            'status' => ['nullable', Rule::enum(FigureStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => '图鑑名称必填',
            'category.enum' => '品类取值不在允许范围',
            'slug.regex' => 'slug 只允许字母、数字、中划线',
            'release_date.before_or_equal' => '发售日期超出合理范围',
        ];
    }
}

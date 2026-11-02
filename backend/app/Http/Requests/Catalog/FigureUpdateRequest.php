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
 * 图鑑条目编辑（T3，仅 admin）。全部字段 `sometimes`：只改传了的字段，
 * 避免运营只改封面却把 name/aliases 一起清空（PUT 全量覆盖在后台表单里极易误伤）。
 */
class FigureUpdateRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'name_ja' => ['sometimes', 'nullable', 'string', 'max:200'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:200'],
            'aliases' => ['sometimes', 'nullable', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:60'],

            'maker' => ['sometimes', 'nullable', 'string', 'max:120'],
            'series' => ['sometimes', 'nullable', 'string', 'max:120'],
            'scale' => ['sometimes', 'nullable', 'string', 'max:24'],
            'category' => ['sometimes', 'nullable', Rule::enum(FigureCategory::class)],
            'release_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:1950-01-01', 'before_or_equal:2100-12-31'],

            'external_ids' => ['sometimes', 'nullable', 'array'],
            'external_ids.*' => ['string', 'max:64'],

            'specs' => ['sometimes', 'nullable', 'array'],
            'specs.*' => ['nullable'],

            'cover_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cover_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'gallery_keys' => ['sometimes', 'nullable', 'array', 'max:20'],
            'gallery_keys.*' => ['string', 'max:255'],
            'gallery_urls' => ['sometimes', 'nullable', 'array', 'max:20'],
            'gallery_urls.*' => ['string', 'max:500'],

            'price_low' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:160', 'regex:/^[a-zA-Z0-9\-_]+$/'],
            'status' => ['sometimes', 'nullable', Rule::enum(FigureStatus::class)],
        ];
    }
}

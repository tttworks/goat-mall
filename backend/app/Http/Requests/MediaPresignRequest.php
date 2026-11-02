<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 媒体直传凭证申请（T3，仅 admin）。
 * 在此拦住非图片 MIME 与超大文件：预签名一旦发出，UFile 不再校验业务规则。
 */
class MediaPresignRequest extends FormRequest
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
            'filename' => ['required', 'string', 'max:200'],
            'mime' => ['required', 'string', Rule::in(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])],
            'size' => ['nullable', 'integer', 'min:1', 'max:10485760'],
            'folder' => ['nullable', 'string', Rule::in(['figures', 'listings', 'posts', 'avatars'])],
        ];
    }
}

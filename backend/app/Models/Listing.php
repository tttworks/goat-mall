<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\ListingStatus;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 在售商品（契约 §2.2 listings）。owner：T4。
 * 媒体字段只存 UFile 对象 key，URL 由 MediaUrl 服务按 MEDIA_BASE_URL 拼。
 */
class Listing extends Model
{
    use SoftDeletes;

    protected $table = 'listings';

    protected $primaryKey = 'listing_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'price' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'stock' => 'integer',
            'gallery_keys' => 'array',
            'meta' => 'array',
            'reserved_until' => 'datetime',
            'published_at' => 'datetime',
            'sold_at' => 'datetime',
        ];
    }

    /** 卖家（users 表与模型由 T2 提供，跨 owner 只建索引不加 FK） */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id', 'user_id');
    }

    /** 图鑑条目（figures 表由 T3/T9 提供，站点只读） */
    public function figure(): BelongsTo
    {
        return $this->belongsTo(Figure::class, 'figure_id', 'figure_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'listing_id', 'listing_id');
    }

    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::Active->value)->where('stock', '>', 0);
    }

    public function isPurchasable(): bool
    {
        return $this->status === ListingStatus::Active
            && $this->stock > 0
            && ($this->reserved_until === null || $this->reserved_until->isPast());
    }

    /** 封面/画廊的对外 URL（只读拼接，不落库） */
    public function coverUrl(): ?string
    {
        return MediaUrl::for($this->cover_key);
    }

    /** @return array<int, string> */
    public function galleryUrls(): array
    {
        return array_values(array_filter(array_map(
            static fn ($key) => MediaUrl::for($key),
            (array) ($this->gallery_keys ?? [])
        )));
    }
}

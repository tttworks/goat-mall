<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\FigureCategory;
use App\Enums\FigureStatus;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * 图鑑条目（契约 §2.1 figures）。owner：T3。
 *
 * 媒体字段只存 UFile 对象 key，URL 由 MediaUrl 按 MEDIA_BASE_URL 拼（DB 不落完整 URL）。
 * 搜索：Meilisearch（Scout），索引设置集中在 config/scout.php 的 goat_figures，
 * 不把 settings 写进模型，避免 T9 采集侧改配置时两边打架。
 *
 * @property int $figure_id
 * @property string $slug
 * @property string $name
 * @property string|null $name_ja
 * @property string|null $name_en
 * @property array<int, string>|null $aliases
 * @property string|null $maker
 * @property string|null $series
 * @property string|null $scale
 * @property FigureCategory $category
 * @property int|null $year
 * @property \Illuminate\Support\Carbon|null $release_date
 * @property array<string, string>|null $external_ids
 * @property array<string, mixed>|null $specs
 * @property string|null $cover_key
 * @property array<int, string>|null $gallery_keys
 * @property string|null $price_low
 * @property FigureStatus $status
 * @property int $views
 * @property \Illuminate\Support\Carbon|null $published_at
 */
class Figure extends Model
{
    use Searchable, SoftDeletes;

    protected $table = 'figures';

    protected $primaryKey = 'figure_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'external_ids' => 'array',
            'specs' => 'array',
            'gallery_keys' => 'array',
            'category' => FigureCategory::class,
            'status' => FigureStatus::class,
            'year' => 'integer',
            'views' => 'integer',
            'release_date' => 'date',
            'published_at' => 'datetime',
            'price_low' => 'decimal:2',
        ];
    }

    /** 溯源记录（同一条目在 MFC/78dm/AmiAmi 的原始数据） */
    public function sources(): HasMany
    {
        return $this->hasMany(FigureSource::class, 'figure_id', 'figure_id');
    }

    /** 挂在该图鑑下的商品（T4 拥有 listings，此处只读） */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'figure_id', 'figure_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', FigureStatus::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->status === FigureStatus::Published;
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

    /** 别名 + 中日英名去空去重：搜索与展示共用一份归一化结果 */
    public function allNames(): array
    {
        return array_values(array_unique(array_filter([
            $this->name,
            $this->name_ja,
            $this->name_en,
            ...(array) ($this->aliases ?? []),
        ], static fn ($name) => is_string($name) && trim($name) !== '')));
    }

    /** 索引名不带前缀，前缀统一由 config('scout.prefix') = goat_ 生成 → goat_figures */
    public function searchableAs(): string
    {
        return 'figures';
    }

    /** 草稿/归档不进索引：否则未发布条目会从搜索里漏出去 */
    public function shouldBeSearchable(): bool
    {
        return $this->status === FigureStatus::Published;
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return [
            'figure_id' => (int) $this->figure_id,
            'name' => (string) $this->name,
            'name_ja' => (string) $this->name_ja,
            'name_en' => (string) $this->name_en,
            'aliases' => array_values((array) ($this->aliases ?? [])),
            'series' => (string) $this->series,
            'maker' => (string) $this->maker,
            'scale' => (string) $this->scale,
            'category' => $this->category->value,
            'year' => $this->year === null ? null : (int) $this->year,
            'status' => $this->status->value,
            'price_low' => $this->price_low === null ? null : (float) $this->price_low,
            'published_at' => $this->published_at?->getTimestamp(),
        ];
    }
}

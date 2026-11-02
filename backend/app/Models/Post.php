<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 社群图片帖（T6）。
 *
 * 展示口径单点定义在 scopeVisible()：approved 且 published_at 非空。
 * 业务代码一律用 scope，不要在别处手写 where('status', ...)。
 */
class Post extends Model
{
    use SoftDeletes;

    protected $table = 'posts';

    protected $primaryKey = 'post_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'images' => 'array',
            'moderation' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return HasMany<PostLike, $this> */
    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class, 'post_id', 'post_id');
    }

    /**
     * 对外可见的评论：只算 approved，机审待定与未通过的评论不进计数也不进列表。
     *
     * 用全部评论的计数会让「评论数 5、列表 3 条」这种对不上的现象出现，
     * 用户第一反应是平台吞评论。
     *
     * @return HasMany<PostComment, $this>
     */
    public function visibleComments(): HasMany
    {
        return $this->hasMany(PostComment::class, 'post_id', 'post_id')
            ->where('status', CommentStatus::Approved->value);
    }

    /** @return HasMany<PostComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class, 'post_id', 'post_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->where('status', PostStatus::Approved->value)
            ->whereNotNull('published_at');
    }

    /** @return array<int, string> */
    public function imageUrls(): array
    {
        return array_values(array_filter(array_map(
            static fn ($key) => MediaUrl::for($key),
            (array) ($this->images ?? [])
        )));
    }

    public function isVisible(): bool
    {
        return $this->status->isPublic() && $this->published_at !== null;
    }

    /** 状态跃迁（唯一入口），先按枚举断言方向再落库 */
    public function transitTo(PostStatus $to, array $attributes = []): self
    {
        $this->status = $this->status->assertTransitionTo($to);
        $this->fill($attributes)->save();

        return $this;
    }
}

<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Models;

use App\Enums\CommentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 帖子评论（T6）。
 *
 * 软删除而非物理删：运营复盘/申诉时需要看到「用户自己删了什么」，
 * 物理删除会让举报工单里的证据对不上。
 */
class PostComment extends Model
{
    use SoftDeletes;

    protected $table = 'post_comments';

    protected $primaryKey = 'comment_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CommentStatus::class,
            'moderation' => 'array',
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id', 'post_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function isVisible(): bool
    {
        return $this->status === CommentStatus::Approved;
    }
}

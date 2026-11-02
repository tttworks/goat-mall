<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Catalog;

use App\Enums\FigureCategory;
use App\Enums\FigureStatus;
use App\Exceptions\CatalogException;
use App\Models\Figure;
use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 图鑑条目的写侧服务（T3）：录入 / 编辑 / 发布 / 归档 / 删除。
 *
 * 三条不变式（改这个类之前先确认没有破坏它们）：
 *  1. 发布权只在管理员：status=published 只能经由 publish()/changeStatus() 触发，且必定写 published_at；
 *  2. 媒体只落 key：入参可以是 URL 或 key，统一经 MediaUrl 归一化成 key，外站 URL 一律拒绝；
 *  3. 外部 ID 双向同步：figures.external_ids（展示/索引用）与 figure_sources（溯源自证）必须一致。
 */
class FigureService
{
    /** 允许的外部源（与 figure_sources.check 约束、T9 adapters 保持一致） */
    public const SOURCES = ['mfc', '78dm', 'amiAmi'];

    /**
     * 新建图鑑条目。默认 draft，只有显式传 status=published 才直接发布。
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $admin = null): Figure
    {
        $payload = $this->normalize($data);
        $payload['slug'] = $this->uniqueSlug($payload['slug'] ?? $payload['name']);

        // 显式补默认值：PG 靠 INSERT..RETURNING 回填库默认列，SQLite 不回填，
        // 不补的话新建对象 status 为 null，出参读 ->value 直接 500（测试环境才暴露）
        $payload['status'] ??= FigureStatus::Draft->value;
        $payload['category'] ??= FigureCategory::Other->value;

        if ($payload['status'] === FigureStatus::Published->value) {
            $payload['published_at'] = now();
        }

        return DB::transaction(function () use ($payload, $data) {
            $figure = Figure::create($payload);

            $this->syncSources($figure, (array) ($data['external_ids'] ?? []));

            return $figure;
        });
    }

    /**
     * 编辑条目。未传的字段保持不变（partial update 语义由调用方用 validated() 保证）。
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Figure $figure, array $data): Figure
    {
        $payload = $this->normalize($data);

        // 改名不自动改 slug：已发布条目的 URL 必须稳定（外链/分享/搜索引擎缓存都指向旧 slug）
        if (! empty($data['slug']) && $data['slug'] !== $figure->slug) {
            $payload['slug'] = $this->uniqueSlug($data['slug'], $figure->figure_id);
        } else {
            unset($payload['slug']);
        }

        return DB::transaction(function () use ($figure, $payload, $data) {
            // 直接置为 published 时补 published_at；已是 published 的保持不变，避免刷新时间
            if (($payload['status'] ?? null) === FigureStatus::Published->value && $figure->published_at === null) {
                $payload['published_at'] = now();
            }

            $figure->fill($payload)->save();

            if (array_key_exists('external_ids', $data)) {
                $this->syncSources($figure, (array) $data['external_ids']);
            }

            return $figure->refresh();
        });
    }

    /** 发布：草稿 → 已发布（重复发布幂等，不刷新 published_at） */
    public function publish(Figure $figure): Figure
    {
        return $this->changeStatus($figure, FigureStatus::Published);
    }

    /** 归档：从对外可见撤下，但保留条目（历史交易/行情仍可回查） */
    public function archive(Figure $figure): Figure
    {
        return $this->changeStatus($figure, FigureStatus::Archived);
    }

    public function changeStatus(Figure $figure, FigureStatus $status): Figure
    {
        if ($figure->status === $status) {
            return $figure;
        }

        $figure->status = $status;

        if ($status === FigureStatus::Published && $figure->published_at === null) {
            $figure->published_at = now();
        }

        $figure->save();

        return $figure;
    }

    /**
     * 删除（软删）。已绑定商品的条目不允许删：商品会变成「无图鑑」孤儿，
     * 让卖家商品页丢图鑑信息属于业务事故，先让运营把商品改挂其他条目再删。
     */
    public function delete(Figure $figure): void
    {
        $boundListings = $figure->listings()->count();

        if ($boundListings > 0) {
            throw CatalogException::conflict("该条目下还挂着 {$boundListings} 个商品，请先解绑后再删除");
        }

        $figure->delete();
    }

    /**
     * 入参归一化：媒体 key、别名、外部 ID、年份派生。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $payload = [];

        // 文本字段：只拷允许的键，避免前端顺带把 figure_id/views 等内部字段写进库
        foreach (['name', 'name_ja', 'name_en', 'maker', 'series', 'scale', 'release_date', 'price_low'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $this->blankToNull($data[$field]);
            }
        }

        if (array_key_exists('category', $data) && $data['category'] !== null) {
            $payload['category'] = FigureCategory::from((string) $data['category'])->value;
        }

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $payload['status'] = FigureStatus::from((string) $data['status'])->value;
        }

        if (array_key_exists('aliases', $data)) {
            $payload['aliases'] = $this->normalizeAliases($data['aliases']);
        }

        if (array_key_exists('specs', $data)) {
            $payload['specs'] = $data['specs'] === null ? null : (array) $data['specs'];
        }

        if (array_key_exists('external_ids', $data)) {
            // 空集合落 null 而不是 []：jsonb 里两种「空」会让下游判断分叉
            $normalized = $this->normalizeExternalIds($data['external_ids']);
            $payload['external_ids'] = $normalized === [] ? null : $normalized;
        }

        // 媒体：兼容前端传 cover_url（完整 URL）或 cover_key（对象 key），统一落 key
        if (array_key_exists('cover_url', $data) || array_key_exists('cover_key', $data)) {
            $payload['cover_key'] = $this->toMediaKey($data['cover_url'] ?? $data['cover_key'] ?? null, '封面');
        }

        if (array_key_exists('gallery_urls', $data) || array_key_exists('gallery_keys', $data)) {
            $payload['gallery_keys'] = $this->toMediaKeys((array) ($data['gallery_urls'] ?? $data['gallery_keys'] ?? []));
        }

        if (array_key_exists('slug', $data) && $data['slug'] !== null && $data['slug'] !== '') {
            $payload['slug'] = Str::slug((string) $data['slug']);
        }

        // year 由 release_date 派生（前端不必两个字段都传）；显式传 release_date=null 时一并清空
        if (array_key_exists('release_date', $data)) {
            $payload['year'] = $payload['release_date'] === null
                ? null
                : (int) substr((string) $payload['release_date'], 0, 4);
        }

        return $payload;
    }

    /** @return array<int, string> */
    private function normalizeAliases(mixed $aliases): array
    {
        return collect((array) $aliases)
            ->map(fn ($alias) => is_string($alias) ? trim($alias) : '')
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * 外部 ID 白名单化：只认 mfc/78dm/amiAmi，值转字符串。
     * 未知源直接丢弃而不是报错——采集侧上游新增源时不应阻断人工录入。
     *
     * @return array<string, string>
     */
    private function normalizeExternalIds(mixed $externalIds): array
    {
        $normalized = [];

        foreach ((array) $externalIds as $source => $externalId) {
            if (! in_array($source, self::SOURCES, true)) {
                continue;
            }

            $value = trim((string) $externalId);
            if ($value !== '') {
                $normalized[$source] = $value;
            }
        }

        return $normalized;
    }

    /** URL/key → 对象 key；外站 URL 直接拒绝（不允许把别人 CDN 当图床，防盗链一挂整页破图） */
    private function toMediaKey(mixed $value, string $label): ?string
    {
        $value = $this->blankToNull($value);

        if ($value === null) {
            return null;
        }

        $key = MediaUrl::keyOf((string) $value);

        if ($key === null) {
            throw CatalogException::invalid("{$label}必须是本站上传后的对象 key（不接受外站图片 URL）");
        }

        return $key;
    }

    /** @return array<int, string>|null */
    private function toMediaKeys(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        $keys = array_map(fn ($value) => $this->toMediaKey($value, '画廊图'), $values);

        return array_values(array_filter($keys));
    }

    /**
     * 生成唯一 slug。中文名 Str::slug 后会变空，此时退化为 figure-<随机>，
     * 保证 URL 可用而不是拼出 /figures/ 这种空路径。
     */
    private function uniqueSlug(string $base, ?int $ignoreFigureId = null): string
    {
        $slug = Str::slug($base);

        // 纯数字 slug 会与 figure_id 撞空间：中文名转写后可能只剩数字（如「… 1/7」→ 17），
        // 详情页按数字优先判 id，这类条目将永远 404，必须加前缀区分
        if ($slug === '') {
            $slug = 'figure-'.Str::lower(Str::random(8));
        } elseif (ctype_digit($slug)) {
            $slug = 'figure-'.$slug;
        }

        $slug = Str::limit($slug, 120, '');
        $candidate = $slug;
        $suffix = 1;

        while ($this->slugExists($candidate, $ignoreFigureId)) {
            $candidate = $slug.'-'.(++$suffix);
        }

        return $candidate;
    }

    private function slugExists(string $slug, ?int $ignoreFigureId): bool
    {
        return Figure::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreFigureId !== null, fn ($query) => $query->where('figure_id', '!=', $ignoreFigureId))
            ->exists();
    }

    /**
     * 同步溯源自证表：删掉本次未提交的源，写入/更新本次提交的源。
     *
     * 用 updateOrInsert 而不是 save()：figure_sources 是复合主键，Eloquent 无法表达，
     * 而 updateOrInsert 天然幂等（采集侧重复投递同一 external_id 不会炸唯一键）。
     */
    private function syncSources(Figure $figure, array $externalIds): void
    {
        $normalized = $this->normalizeExternalIds($externalIds);
        $now = now();

        DB::transaction(function () use ($figure, $normalized, $now) {
            DB::table('figure_sources')
                ->where('figure_id', $figure->figure_id)
                ->when($normalized !== [], fn ($query) => $query->whereNotIn('source', array_keys($normalized)))
                ->delete();

            if ($normalized !== []) {
                $rows = [];
                foreach ($normalized as $source => $externalId) {
                    $rows[] = [
                        'source' => $source,
                        'external_id' => $externalId,
                        'figure_id' => $figure->figure_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                // upsert 而非 updateOrInsert：冲突时只更新 figure_id/updated_at，
                // 保留 created_at（第一次入库时间，是溯源证据，不能被后续编辑刷掉）
                DB::table('figure_sources')->upsert($rows, ['source', 'external_id'], ['figure_id', 'updated_at']);
            }

            // 回写 figures.external_ids，保证两条链路一致（以本次提交的集合为准）
            if ($figure->external_ids !== $normalized) {
                $figure->forceFill(['external_ids' => $normalized === [] ? null : $normalized])->saveQuietly();
            }
        });
    }

    private function blankToNull(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }
}

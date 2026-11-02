<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Catalog;

use App\Exceptions\CatalogException;
use App\Support\MediaUrl;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * 媒体上传（T3）：UFile 直传预签名 + 本地/兜底直存。
 *
 * 为什么不让图片过 PHP：图鑑封面动辄 3~8MB，走 Octane 进程会白占 worker 与带宽；
 * 生产环境用 S3 预签名让浏览器直传 UFile，PHP 只发签名。
 * 本地开发没有 UFile 凭据时（disk=ufile_local）退化为服务端直接落盘，两种模式对前端只差 mode 字段。
 *
 * 落库永远只存 key（契约 §2），URL 由 MediaUrl 现拼。
 */
class MediaPresigner
{
    /** 允许上传的业务目录：白名单防止前端传 ../ 覆盖别的对象 */
    private const FOLDERS = ['figures', 'listings', 'posts', 'avatars'];

    /** 允许的图片类型（与 UFile bucket 策略、前端 <input accept> 一致） */
    private const MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    /**
     * 生成直传凭证。
     *
     * @return array{mode: string, key: string, url: string, method: string, headers: array<string, string>, expires_at: string|null}
     */
    public function presign(string $filename, string $mime, string $folder = 'figures'): array
    {
        if (! in_array($folder, self::FOLDERS, true)) {
            throw CatalogException::invalid('上传目录不合法');
        }

        $extension = self::MIMES[$mime] ?? $this->extensionOf($filename);
        $key = $this->buildKey($folder, $extension);
        $disk = $this->disk();

        // 本地盘无预签名能力 → 告诉前端改用服务端上传端点（同一 key 规则，前端无感）
        if (! $this->supportsPresign($disk)) {
            return [
                'mode' => 'direct',
                'key' => $key,
                'url' => route('api.v1.admin.media.store'),
                'method' => 'POST',
                'headers' => [],
                'expires_at' => null,
            ];
        }

        try {
            /** @var Filesystem&\Illuminate\Contracts\Filesystem\Cloud $disk */
            $ttl = now()->addMinutes((int) config('goat.media.presign_ttl', 15));
            $payload = $disk->temporaryUploadUrl($key, $ttl, ['ContentType' => $mime]);
        } catch (Throwable $e) {
            report($e);

            throw CatalogException::storageUnavailable();
        }

        return [
            'mode' => 'presigned',
            'key' => $key,
            // S3 兼容端点的签名 URL 已含鉴权参数，前端 PUT 即可
            'url' => (string) $payload['url'],
            'method' => 'PUT',
            'headers' => array_map('strval', (array) ($payload['headers'] ?? ['Content-Type' => $mime])),
            'expires_at' => $ttl->toIso8601String(),
        ];
    }

    /**
     * 服务端上传兜底（本地开发 / 客户端不支持直传）。
     */
    public function store(UploadedFile $file, string $folder = 'figures'): string
    {
        if (! in_array($folder, self::FOLDERS, true)) {
            throw CatalogException::invalid('上传目录不合法');
        }

        $extension = $this->extensionOf($file->getClientOriginalName());
        $key = $this->buildKey($folder, $extension);

        try {
            $this->disk()->put($key, $file->getContent(), ['visibility' => 'public']);
        } catch (Throwable $e) {
            report($e);

            throw CatalogException::storageUnavailable();
        }

        return $key;
    }

    /** key → 对外 URL（后台返回给编辑器的预览地址） */
    public function url(?string $key): ?string
    {
        return MediaUrl::for($key);
    }

    private function buildKey(string $folder, string $extension): string
    {
        // 按年月分目录：单目录对象数可控，UFile 列举/清理更快
        return sprintf('%s/%s/%s.%s', $folder, now()->format('Y/m'), Str::uuid()->toString(), $extension);
    }

    private function extensionOf(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)
            ? ($extension === 'jpeg' ? 'jpg' : $extension)
            : 'jpg';
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('goat.media.disk', 'ufile'));
    }

    /** 只有 S3 兼容盘支持 temporaryUploadUrl */
    private function supportsPresign(Filesystem $disk): bool
    {
        return (string) config('filesystems.disks.'.config('goat.media.disk').'.driver') === 's3'
            && method_exists($disk, 'temporaryUploadUrl');
    }
}

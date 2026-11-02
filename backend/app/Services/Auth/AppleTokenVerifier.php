<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Services\Auth;

use App\Exceptions\AuthException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Apple Sign in 身份令牌校验（T2）。
 *
 * App Store 上架硬要求：iOS 端必须提供 Sign in with Apple，因此这里做**真实签名校验**，
 * 不接受"解出 payload 就用"（否则任何人可伪造 sub 登录他人账号）。
 *
 * 实现方式：拉 Apple JWKS → 按 kid 取 RSA 公钥 → openssl 验 RS256 → 校验 iss/aud/exp。
 * 不引入第三方 JWT 库，减少供应链面；JWKS 缓存 12 小时，避免每次登录打 Apple。
 */
class AppleTokenVerifier
{
    /**
     * @return array{sub: string, email?: string, email_verified?: bool, is_private_email?: bool}
     */
    public function verify(string $identityToken): array
    {
        $segments = explode('.', trim($identityToken));

        if (count($segments) !== 3) {
            throw AuthException::unauthorized('Apple 令牌格式不正确');
        }

        [$headerB64, $payloadB64, $signatureB64] = $segments;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $claims = json_decode($this->base64UrlDecode($payloadB64), true);

        if (! is_array($header) || ! is_array($claims)) {
            throw AuthException::unauthorized('Apple 令牌无法解析');
        }

        if (($header['alg'] ?? '') !== 'RS256') {
            Log::channel('security')->warning('apple.verify 非 RS256 算法', ['alg' => $header['alg'] ?? null]);

            throw AuthException::unauthorized('Apple 令牌算法不受支持');
        }

        $publicKey = $this->publicKeyFor((string) ($header['kid'] ?? ''));

        $verified = openssl_verify(
            $headerB64.'.'.$payloadB64,
            $this->base64UrlDecode($signatureB64),
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            throw AuthException::unauthorized('Apple 令牌签名校验失败');
        }

        $this->assertClaims($claims);

        return $claims;
    }

    private function assertClaims(array $claims): void
    {
        $issuer = (string) config('goat_auth.apple.issuer', 'https://appleid.apple.com');

        if (($claims['iss'] ?? null) !== $issuer) {
            throw AuthException::unauthorized('Apple 令牌签发方不正确');
        }

        $audience = (string) config('goat_auth.apple.client_id');

        if ($audience !== '' && ($claims['aud'] ?? null) !== $audience) {
            throw AuthException::unauthorized('Apple 令牌受众不匹配');
        }

        if (empty($claims['sub'])) {
            throw AuthException::unauthorized('Apple 令牌缺少用户标识');
        }

        if (isset($claims['exp']) && (int) $claims['exp'] <= time()) {
            throw AuthException::unauthorized('Apple 令牌已过期');
        }
    }

    /** 取 kid 对应公钥（JWKS 带缓存；kid 未命中时强制刷新一次，应对 Apple 轮换密钥） */
    private function publicKeyFor(string $kid): string
    {
        $keys = $this->jwks();

        if (! isset($keys[$kid])) {
            $keys = $this->jwks(force: true);
        }

        if (! isset($keys[$kid])) {
            throw AuthException::unauthorized('Apple 公钥未找到，请重新登录');
        }

        return $keys[$kid];
    }

    /** @return array<string, string> kid => PEM 公钥 */
    private function jwks(bool $force = false): array
    {
        $cacheKey = 'goat:apple:jwks';

        if ($force) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, (int) config('goat_auth.apple.jwks_ttl', 43200), function (): array {
            $url = (string) config('goat_auth.apple.jwks_url', 'https://appleid.apple.com/auth/keys');

            try {
                $response = Http::timeout(5)->retry(2, 200)->get($url);
            } catch (\Throwable $e) {
                Log::channel('security')->error('apple.jwks 拉取失败', ['error' => $e->getMessage()]);

                throw AuthException::provider('Apple 登录服务暂不可用');
            }

            if ($response->failed()) {
                throw AuthException::provider('Apple 登录服务暂不可用');
            }

            $pems = [];

            foreach ((array) $response->json('keys', []) as $jwk) {
                if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['kid']) || empty($jwk['n']) || empty($jwk['e'])) {
                    continue;
                }

                $pems[(string) $jwk['kid']] = $this->rsaPublicKeyPem((string) $jwk['n'], (string) $jwk['e']);
            }

        // 缓存值里不能放资源句柄，只放 PEM 字符串
            return $pems;
        });
    }

    /** 由 JWK 的 n/e 构造 SPKI PEM（RSA 公钥），避免依赖 openssl 扩展的 JWK 支持 */
    private function rsaPublicKeyPem(string $modulusB64, string $exponentB64): string
    {
        $modulus = $this->asn1Integer($this->base64UrlDecode($modulusB64));
        $exponent = $this->asn1Integer($this->base64UrlDecode($exponentB64));

        $rsaPublicKey = $this->asn1(0x30, $modulus.$exponent);
        $algorithmId = $this->asn1(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $subjectPublicKey = $this->asn1(0x03, "\x00".$rsaPublicKey);
        $spki = $this->asn1(0x30, $algorithmId.$subjectPublicKey);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function asn1Integer(string $bytes): string
    {
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\x00".$bytes; // 最高位为 1 时补 0，避免被解析成负数
        }

        return $this->asn1(0x02, $bytes);
    }

    private function asn1(int $tag, string $value): string
    {
        $length = strlen($value);

        if ($length < 0x80) {
            return chr($tag).chr($length).$value;
        }

        $lengthBytes = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(0x80 | strlen($lengthBytes)).$lengthBytes.$value;
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw AuthException::unauthorized('Apple 令牌编码损坏');
        }

        return $decoded;
    }
}

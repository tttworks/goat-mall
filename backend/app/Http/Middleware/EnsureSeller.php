<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Http\Middleware;

use App\Exceptions\AuthException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 卖家守卫（T4）：要求店铺状态 active 且实名 approved。
 *
 * 不用 role:admin/seller 判断的原因：卖家身份不是「用户角色」而是「店铺状态 + 实名状态」的组合，
 * 卖家被封禁/实名人被撤销后，用户角色仍是 seller，但已经不该能上架。
 * 单一判断入口放在 SellerProfile::canList()，与申请/审核逻辑共用同一口径。
 */
class EnsureSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw AuthException::unauthorized();
        }

        $profile = $user->sellerProfile;

        if ($profile === null) {
            throw AuthException::forbidden('请先开店（提交店铺资料并通过实名核身）');
        }

        if (! $profile->canList()) {
            throw AuthException::forbidden('店铺未通过审核或实名核身已失效，暂不能上架商品');
        }

        return $next($request);
    }
}

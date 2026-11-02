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
 * 角色守卫（T2）：路由用 `->middleware('role:seller,admin')` 形式声明。
 *
 * 为什么不用 Gate/Policy：本项目权限维度只有角色 + 资源归属两类，
 * 角色维度集中在这个中间件，资源归属在 controller 内校验（如订单只有买家本人可取消），
 * 避免几十个 Policy 文件分散审阅注意力。
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw AuthException::unauthorized();
        }

        if ($roles !== [] && ! in_array($user->role->value, $roles, true)) {
            throw AuthException::forbidden('当前身份无权访问该接口');
        }

        return $next($request);
    }
}

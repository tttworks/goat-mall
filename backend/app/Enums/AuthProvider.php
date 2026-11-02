<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

/**
 * 登录通道（T2）。合同要求四通道：用户名 / 邮箱 / 微信 openid / Apple Sign in。
 * phone 预留但**渠道关闭**（零采购原则：初期不启用短信）。
 */
enum AuthProvider: string
{
    case Username = 'username';
    case Email = 'email';
    case Wechat = 'wechat';
    case Apple = 'apple';
    case Phone = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::Username => '用户名',
            self::Email => '邮箱',
            self::Wechat => '微信',
            self::Apple => 'Apple',
            self::Phone => '手机号',
        };
    }

    /** 该通道是否使用密码（OAuth 通道无密码） */
    public function usesPassword(): bool
    {
        return $this === self::Username || $this === self::Email || $this === self::Phone;
    }

    /** 是否当前启用（phone 预留，配置可一键开关） */
    public function enabled(): bool
    {
        return (bool) config('goat.auth.channels.'.$this->value, false);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

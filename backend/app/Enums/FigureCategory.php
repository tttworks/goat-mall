<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

namespace App\Enums;

/**
 * 图鑑品类（T3）：参考 mmacg.tw 的品类划分（比例手办 / 景品 / 一番赏是三种不同交易逻辑的货）。
 *
 * 为什么不只存自由文本：品类决定列表页默认筛选器与详情页展示模块，
 * 自由文本会让 T7 前端无法稳定渲染，故收敛为枚举 + other 兜底。
 */
enum FigureCategory: string
{
    /** 比例手办（1/7、1/4 等，单价高、再版频繁） */
    case ScaleFigure = 'scale_figure';

    /** 景品（抓娃娃机/夹机，低价高频） */
    case Prize = 'prize';

    /** 一番赏（抽赏玩法，成套拆售） */
    case IchibanKuji = 'ichiban_kuji';

    /** 可动手办（figma/SHF 类，配件多） */
    case ActionFigure = 'action_figure';

    /** 雕像/大比例树脂（重货，运费特殊） */
    case Statue = 'statue';

    /** 拼装模型（高达/MG/PG） */
    case ModelKit = 'model_kit';

    /** 毛绒/抱枕 */
    case Plush = 'plush';

    /** 亚克力立牌/挂件 */
    case Acrylic = 'acrylic';

    /** 卡牌（集换式，品相分级严格） */
    case Card = 'card';

    /** 其他/待归类 */
    case Other = 'other';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::ScaleFigure => '比例手办',
            self::Prize => '景品',
            self::IchibanKuji => '一番赏',
            self::ActionFigure => '可动手办',
            self::Statue => '雕像/大比例',
            self::ModelKit => '拼装模型',
            self::Plush => '毛绒',
            self::Acrylic => '亚克力',
            self::Card => '卡牌',
            self::Other => '其他',
        };
    }

    /** 前端筛选器用（value + 中文名一次取回，避免前端再维护一份映射） */
    public static function options(): array
    {
        return array_map(
            static fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}

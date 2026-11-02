<?php

/**
 * GOAT 潮玩交易平台 / GOAT 潮玩数据采集
 * Copyright © 火山文化科技（天津市火山文化科技有限责任公司）
 * Author: Aloysius Luo
 * 本文件为 GOAT 自有代码；第三方框架（Laravel / uni-app / 等）版权归各自权利人。
 */

/**
 * 全文搜索（T1）：Meilisearch 自建（零采购），索引前缀统一 goat_，与采集系统共用实例但索引隔离。
 */
return [
    'driver' => env('SCOUT_DRIVER', 'meilisearch'),

    'prefix' => env('SCOUT_PREFIX', 'goat_'),

    'queue' => env('SCOUT_QUEUE', false),

    'after_commit' => true,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    'soft_delete' => true,

    'identify' => env('SCOUT_IDENTIFY', false),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            // 图鑑（T3 定义字段，索引设置在此集中，避免散落在模型里）
            'goat_figures' => [
                'filterableAttributes' => ['maker', 'series', 'scale', 'year', 'status'],
                'sortableAttributes' => ['year', 'price_low', 'published_at'],
                'searchableAttributes' => ['name', 'name_ja', 'name_en', 'series', 'maker', 'aliases'],
            ],
        ],
    ],
];

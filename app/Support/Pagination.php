<?php

namespace App\Support;

/**
 * 分页参数统一解析器（全站唯一收口）
 *
 * 钳制语义：page < 1 回落 1；pageSize 超过上限裁到上限；缺失/非数字/≤0
 * 取端点默认值。兼容 snake_case（服务层 validated 数据）与 camelCase
 * （裸控制器入参）两种键名。响应信封的 page/pageSize 语义以此为准。
 */
class Pagination
{
    public const MAX_SIZE = 100;

    /**
     * @param array<string, mixed> $params 入参（query 或 validated 数组）
     * @param int $defaultSize 该端点的默认页大小
     * @return array{page: int, size: int}
     */
    public static function resolve(array $params, int $defaultSize = 20): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));

        $rawSize = $params['page_size'] ?? $params['pageSize'] ?? null;
        $size = is_numeric($rawSize) ? (int) $rawSize : 0;
        if ($size < 1) {
            $size = $defaultSize;
        }

        return ['page' => $page, 'size' => min($size, self::MAX_SIZE)];
    }
}

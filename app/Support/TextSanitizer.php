<?php

namespace App\Support;

/**
 * 自由文本净化器（存储型 XSS 写侧防御）
 *
 * 仅当文本含真实标记特征（< 后跟字母、斜杠或感叹号）时执行标签剥离，
 * 纯文本中的比较符（如 "1 < 2 > 3"）不受影响；剥离后修剪首尾空白。
 * 调用方必须在长度校验之前接入，使 max/min 按净化后的文本计长。
 */
class TextSanitizer
{
    private const MARKUP_PATTERN = '/<[a-zA-Z\/!]/';

    public static function clean(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        if (preg_match(self::MARKUP_PATTERN, $value)) {
            return trim(strip_tags($value));
        }

        return $value;
    }

    /**
     * 递归净化数组内的全部字符串值（如游戏成绩 metadata JSON），
     * 数组键与非字符串值（数字、布尔、null）原样保留。
     */
    public static function cleanDeep(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::cleanDeep(...), $value);
        }

        return self::clean($value);
    }
}

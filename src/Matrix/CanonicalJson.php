<?php

namespace Dgtlss\Clearance\Matrix;

final class CanonicalJson
{
    public static function encode(array $value): string
    {
        return json_encode(self::sort($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    public static function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::sort($item);
            }
        }

        return $value;
    }
}

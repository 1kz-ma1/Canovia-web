<?php

namespace App\Intelligence\Support;

use BackedEnum;
use DateTimeInterface;
use JsonException;
use UnitEnum;

final class CanonicalJson
{
    /**
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\\TH:i:s.uP');
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::normalize(...), $value);
            }

            ksort($value, SORT_STRING);

            foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item);
            }

            return $value;
        }

        if (is_object($value)) {
            return self::normalize(get_object_vars($value));
        }

        return $value;
    }
}

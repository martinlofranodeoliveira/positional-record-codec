<?php

declare(strict_types=1);

namespace Lofrano\PositionalCodec;

/** Bounded scalar-value columns, without mbstring or grapheme segmentation. */
final class ColumnSlicer
{
    public static function isValidUtf8(string $bytes): bool
    {
        $length = strlen($bytes);
        for ($i = 0; $i < $length;) {
            $lead = ord($bytes[$i]);
            if ($lead <= 0x7F) {
                $i++;
                continue;
            }
            if ($lead >= 0xC2 && $lead <= 0xDF) {
                $size = 2;
                $low = 0x80;
                $high = 0xBF;
            } elseif ($lead >= 0xE0 && $lead <= 0xEF) {
                $size = 3;
                $low = $lead === 0xE0 ? 0xA0 : 0x80;
                $high = $lead === 0xED ? 0x9F : 0xBF;
            } elseif ($lead >= 0xF0 && $lead <= 0xF4) {
                $size = 4;
                $low = $lead === 0xF0 ? 0x90 : 0x80;
                $high = $lead === 0xF4 ? 0x8F : 0xBF;
            } else {
                return false;
            }
            if ($i + $size > $length) {
                return false;
            }
            $second = ord($bytes[$i + 1]);
            if ($second < $low || $second > $high) {
                return false;
            }
            for ($j = 2; $j < $size; $j++) {
                $next = ord($bytes[$i + $j]);
                if ($next < 0x80 || $next > 0xBF) {
                    return false;
                }
            }
            $i += $size;
        }
        return preg_match('//u', $bytes) === 1;
    }

    public static function isAsciiBytes(string $bytes): bool
    {
        return preg_match('/\A[\x20-\x7E]*\z/', $bytes) === 1;
    }

    public static function hasLineBreak(string $bytes): bool
    {
        return str_contains($bytes, "\r") || str_contains($bytes, "\n")
            || str_contains($bytes, "\xC2\x85")
            || str_contains($bytes, "\xE2\x80\xA8")
            || str_contains($bytes, "\xE2\x80\xA9");
    }

    public static function hasControlCharacters(string $bytes): bool
    {
        return preg_match('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]|\xE2\x80[\xA8\xA9]/', $bytes) === 1;
    }

    /** @return ?list<string> */
    public static function splitColumns(string $text, EncodingMode $mode, int $maxBytes): ?array
    {
        $bytes = strlen($text);
        if ($maxBytes < 1 || $maxBytes > 16_384 || $bytes > $maxBytes) {
            return null;
        }
        if ($mode === EncodingMode::AsciiBytes) {
            return self::isAsciiBytes($text) ? ($text === '' ? [] : str_split($text)) : null;
        }
        // Validate the complete bounded byte sequence before asking PCRE to split it.
        if (!self::isValidUtf8($text)) {
            return null;
        }
        if ($text === '') {
            return [];
        }
        $columns = preg_split('//u', $text, $bytes + 1, PREG_SPLIT_NO_EMPTY);
        return $columns === false ? null : array_values($columns);
    }

    public static function columnLength(string $text, EncodingMode $mode, int $maxBytes = 16_384): ?int
    {
        $columns = self::splitColumns($text, $mode, $maxBytes);
        return $columns === null ? null : count($columns);
    }

    /** @param list<string> $columns */
    public static function slice(array $columns, int $startCol, int $endCol): string
    {
        return implode('', array_slice($columns, $startCol - 1, $endCol - $startCol + 1));
    }
}

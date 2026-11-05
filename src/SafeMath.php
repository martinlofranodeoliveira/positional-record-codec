<?php

declare(strict_types=1);

namespace Lofrano\PositionalCodec;

final class SafeMath
{
    public static function parseUnsignedInt(string $digits): ?int
    {
        if (strlen($digits) > 16_384 || preg_match('/\A[0-9]+\z/', $digits) !== 1) {
            return null;
        }
        $normalized = ltrim($digits, '0');
        if ($normalized === '') {
            return 0;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($max)
            || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            return null;
        }
        return (int) $normalized;
    }

    public static function checkedAdd(int $a, int $b): ?int
    {
        if ($a < 0 || $b < 0 || $a > PHP_INT_MAX - $b) {
            return null;
        }
        return $a + $b;
    }

    public static function decimalStringToMinorUnits(string $decimal, int $scale): ?int
    {
        if ($scale < 0 || $scale > 18 || strlen($decimal) > 16_384) {
            return null;
        }
        if ($scale === 0) {
            return self::parseUnsignedInt($decimal);
        }
        if (preg_match('/\A([0-9]+)\.([0-9]+)\z/', $decimal, $parts) !== 1
            || strlen($parts[2]) !== $scale) {
            return null;
        }
        return self::parseUnsignedInt($parts[1] . $parts[2]);
    }

    public static function minorUnitsToDecimalString(int $minorUnits, int $scale): ?string
    {
        if ($minorUnits < 0 || $scale < 0 || $scale > 18) {
            return null;
        }
        if ($scale === 0) {
            return (string) $minorUnits;
        }
        $padded = str_pad((string) $minorUnits, $scale + 1, '0', STR_PAD_LEFT);
        return substr($padded, 0, -$scale) . '.' . substr($padded, -$scale);
    }
}

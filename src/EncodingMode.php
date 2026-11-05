<?php

declare(strict_types=1);

namespace Lofrano\PositionalCodec;

enum EncodingMode: string
{
    case AsciiBytes = 'ascii_bytes';
    case Utf8Chars = 'utf8_chars';
}

<?php

declare(strict_types=1);

namespace Lofrano\PositionalCodec;

enum FieldType: string
{
    case Alpha = 'alpha';
    case Digits = 'digits';
    case ImpliedDecimal = 'implied_decimal';
    case DateYmd = 'date_ymd';
    case Constant = 'constant';
}

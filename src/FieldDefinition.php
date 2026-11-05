<?php

declare(strict_types=1);

namespace Lofrano\PositionalCodec;

final readonly class FieldDefinition
{
    public int $width;

    public function __construct(
        public string $name,
        public FieldType $type,
        public int $startCol,
        public int $endCol,
        public ?int $scale = null,
        public ?string $constantValue = null,
    ) {
        $this->width = $this->endCol - $this->startCol + 1;
    }
}

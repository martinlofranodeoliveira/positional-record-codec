<?php

declare(strict_types=1);

namespace Lofrano\PositionalCodec;

final readonly class ResourceLimits
{
    public function __construct(
        public int $maxSchemaBytes = 262_144,
        public int $maxJsonDepth = 16,
        public int $maxFieldsPerRecord = 256,
        public int $maxLineLength = 4_096,
        public int $maxLines = 100_000,
        public int $maxLineBytes = 16_384,
        public int $maxBatchBytes = 67_108_864,
        public int $minDetailRecords = 1,
        public int $maxDiagnostics = 100,
        public int $maxRetainedFields = 100_000,
    ) {
        if (PHP_INT_SIZE !== 8) {
            throw CodecException::schema('unsupported_integer_size', 'A 64-bit PHP runtime is required.');
        }
        $bounds = [
            [$maxSchemaBytes, 64, 262_144],
            [$maxJsonDepth, 2, 64],
            [$maxFieldsPerRecord, 1, 256],
            [$maxLineLength, 1, 4_096],
            [$maxLines, 2, 100_000],
            [$maxLineBytes, 1, 16_384],
            [$maxBatchBytes, 1, 67_108_864],
            [$minDetailRecords, 0, $maxLines - 2],
            [$maxDiagnostics, 2, 100],
            [$maxRetainedFields, 1, 100_000],
        ];
        foreach ($bounds as [$value, $min, $max]) {
            if ($value < $min || $value > $max) {
                throw CodecException::schema('invalid_resource_limits', 'Resource limits are outside supported bounds.');
            }
        }
    }
}

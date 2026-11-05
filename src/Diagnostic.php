<?php

declare(strict_types=1);

namespace Lofrano\PositionalCodec;

/**
 * Privacy-safe structural diagnostic.
 *
 * Diagnostics carry only positional coordinates, record role, field name,
 * error code, and a fixed structural description. Raw input slices, field
 * values, and filesystem paths must never be stored in this object.
 */
final readonly class Diagnostic
{
    public function __construct(
        public int $lineNumber,
        public string $recordType,
        public ?string $fieldName,
        public ?int $startCol,
        public ?int $endCol,
        public string $errorCode,
        public string $message,
    ) {
    }

    /**
     * @return array{
     *     line_number: int,
     *     record_type: string,
     *     field_name: ?string,
     *     start_col: ?int,
     *     end_col: ?int,
     *     error_code: string,
     *     message: string
     * }
     */
    public function toArray(): array
    {
        return [
            'line_number' => $this->lineNumber,
            'record_type' => $this->recordType,
            'field_name' => $this->fieldName,
            'start_col' => $this->startCol,
            'end_col' => $this->endCol,
            'error_code' => $this->errorCode,
            'message' => $this->message,
        ];
    }
}

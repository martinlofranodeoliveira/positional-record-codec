<?php

declare(strict_types=1);

namespace Lofrano\PositionalCodec;

use RuntimeException;

final class CodecException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?Diagnostic $diagnostic = null,
    ) {
        parent::__construct($message);
    }

    public static function schema(string $errorCode, string $message): self
    {
        return self::serialization($errorCode, $message, 0, 'schema');
    }

    public static function serialization(
        string $errorCode,
        string $message,
        int $lineNumber,
        string $recordType,
        ?string $fieldName = null,
        ?int $startCol = null,
        ?int $endCol = null,
    ): self {
        return new self($errorCode, $message, new Diagnostic(
            $lineNumber, $recordType, $fieldName, $startCol, $endCol, $errorCode, $message,
        ));
    }
}

<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Lofrano\\PositionalCodec\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    foreach (explode('\\', $relative) as $segment) {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $segment) !== 1) {
            return;
        }
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

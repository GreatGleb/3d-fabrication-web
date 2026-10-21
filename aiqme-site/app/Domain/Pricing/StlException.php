<?php

namespace App\Domain\Pricing;

use RuntimeException;

/** Файл не принят StlAnalyzer; reason — код отказа ядра JS (StlError.code): size, structure, number, coordinate, triangle_count, volume. */
class StlException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid STL: {$reason}");
    }
}

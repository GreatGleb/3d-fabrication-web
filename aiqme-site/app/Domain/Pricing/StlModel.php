<?php

namespace App\Domain\Pricing;

/** Модель STL в единицах файла: число треугольников, габарит [x, y, z], объём, замкнутость сетки (как parseStl ядра JS, без вершин). */
final readonly class StlModel
{
    /** @param  array{float, float, float}  $size */
    public function __construct(
        public int $triangles,
        public array $size,
        public float $volume,
        public bool $closed,
    ) {}
}

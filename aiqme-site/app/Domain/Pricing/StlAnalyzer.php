<?php

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * Серверный анализ STL заявки (§3.1, §6.4, W-11): те же шаги и порядок операций, что у ядра JS
 * resources/js/calculator/core/index.js (паритет — tests/Feature/StlParityTest.php). Координаты приводятся к float32:
 * binary — unpack('g'), ASCII — unpack('g', pack('g', $x)); -0 → 0. Лимиты проверяются до полного разбора.
 */
final class StlAnalyzer
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    public const MAX_TRIANGLES = 500000;

    public const MM_PER_UNIT = ['mm' => 1, 'cm' => 10, 'in' => 25.4];

    public const WARNING_OPEN_MESH = 'open_mesh';

    public const WARNING_EXCEEDS_BUILD_SIZE = 'exceeds_build_size';

    /** Пробелы ASCII (как [ \t\n\v\f\r] ядра JS); \v в PCRE — класс, поэтому \x0B. */
    private const SPACE = " \t\n\x0B\f\r";

    private const NUMBER = '/\A[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\z/';

    /** Файл с диска: размер и счётчик binary проверяются до чтения тела. */
    public function analyzeFile(string $path): StlModel
    {
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new StlException('size');
        }
        if ($size >= 84) {
            $header = (string) file_get_contents($path, false, null, 0, 84);
            $count = unpack('V', $header, 80)[1];
            if (84 + $count * 50 === $size && ($count === 0 || $count > self::MAX_TRIANGLES)) {
                throw new StlException('triangle_count');
            }
        }

        return $this->analyze((string) file_get_contents($path));
    }

    public function analyze(string $bytes): StlModel
    {
        $length = strlen($bytes);
        if ($length > self::MAX_BYTES) {
            throw new StlException('size');
        }
        $count = $length >= 84 ? unpack('V', $bytes, 80)[1] : -1;
        if ($count >= 0 && 84 + $count * 50 === $length) {
            if ($count === 0 || $count > self::MAX_TRIANGLES) {
                throw new StlException('triangle_count');
            }
            $positions = $this->parseBinary($bytes, $count);
        } else {
            $positions = $this->parseAscii($bytes);
        }

        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        foreach ($positions as $i => $value) {
            $axis = $i % 3;
            if ($value < $min[$axis]) {
                $min[$axis] = $value;
            }
            if ($value > $max[$axis]) {
                $max[$axis] = $value;
            }
        }

        // §3.1: |Σ (a−o)·((b−o)×(c−o)) / 6| по треугольникам в порядке файла, o — первая вершина.
        [$ox, $oy, $oz] = [$positions[0], $positions[1], $positions[2]];
        $signedVolume = 0.0;
        for ($i = 0, $n = count($positions); $i < $n; $i += 9) {
            $ax = $positions[$i] - $ox;
            $ay = $positions[$i + 1] - $oy;
            $az = $positions[$i + 2] - $oz;
            $bx = $positions[$i + 3] - $ox;
            $by = $positions[$i + 4] - $oy;
            $bz = $positions[$i + 5] - $oz;
            $cx = $positions[$i + 6] - $ox;
            $cy = $positions[$i + 7] - $oy;
            $cz = $positions[$i + 8] - $oz;
            $signedVolume += ($ax * ($by * $cz - $bz * $cy) + $ay * ($bz * $cx - $bx * $cz) + $az * ($bx * $cy - $by * $cx)) / 6;
        }
        $volume = abs($signedVolume);
        if (! is_finite($volume) || $volume <= 0) {
            throw new StlException('volume');
        }

        return new StlModel(
            intdiv(count($positions), 9),
            [$max[0] - $min[0], $max[1] - $min[1], $max[2] - $min[2]],
            $volume,
            $this->isClosed($positions),
        );
    }

    /**
     * Единицы и масштаб — к объёму (в кубе) и габариту, не к координатам; превышение габарита и незамкнутость — предупреждения.
     *
     * @return array{dimensions_mm: list<float>, volume_cm3: float, warnings: list<string>}
     */
    public function measure(StlModel $model, string $unit, int|float $scale, int|float $maxDimensionMm): array
    {
        if (! isset(self::MM_PER_UNIT[$unit])) {
            throw new InvalidArgumentException('unit');
        }
        if (! is_finite($scale) || $scale < 1 || $scale > 1000) {
            throw new InvalidArgumentException('scale');
        }
        if (! is_finite($maxDimensionMm) || $maxDimensionMm <= 0) {
            throw new InvalidArgumentException('maxDimensionMm');
        }
        $k = self::MM_PER_UNIT[$unit] * $scale / 100;
        $dimensions = array_map(fn (float $size): float => $size * $k, $model->size);
        $warnings = [];
        if (! $model->closed) {
            $warnings[] = self::WARNING_OPEN_MESH;
        }
        if (max($dimensions) > $maxDimensionMm) {
            $warnings[] = self::WARNING_EXCEEDS_BUILD_SIZE;
        }

        return ['dimensions_mm' => $dimensions, 'volume_cm3' => $model->volume * $k * $k * $k / 1000, 'warnings' => $warnings];
    }

    /** Координата как в binary STL: float32, конечная, -0 → 0 (иначе -0 и 0 дали бы разные рёбра). */
    private static function coordinate(float $value): float
    {
        $x = unpack('g', pack('g', $value))[1];
        if (! is_finite($x)) {
            throw new StlException('coordinate');
        }

        return $x == 0 ? 0.0 : $x;
    }

    /** @return list<float> */
    private function parseBinary(string $bytes, int $count): array
    {
        $positions = [];
        for ($t = 0; $t < $count; $t++) {
            // 12 байт нормали пропускаются.
            foreach (unpack('g9', $bytes, 84 + $t * 50 + 12) as $value) {
                if (! is_finite($value)) {
                    throw new StlException('coordinate');
                }
                $positions[] = $value == 0 ? 0.0 : $value;
            }
        }

        return $positions;
    }

    /**
     * ASCII: solid <имя> (facet normal n n n outer loop (vertex x y z)×3 endloop endfacet)+ endsolid [<имя>].
     * Байты читаются как latin1 (как TextDecoder('latin1') ядра), лексемы — по пробелам ASCII.
     *
     * @return list<float>
     */
    private function parseAscii(string $text): array
    {
        if (! preg_match('/\A[ \t\n\x0B\f\r]*solid(?=[ \t\n\x0B\f\r]|\z)[^\n]*/', $text, $header)) {
            throw new StlException('structure');
        }
        $position = strlen($header[0]);
        $length = strlen($text);
        $next = function () use ($text, $length, &$position): ?string {
            $position += strspn($text, self::SPACE, $position);
            if ($position >= $length) {
                return null;
            }
            $size = strcspn($text, self::SPACE, $position);
            $token = substr($text, $position, $size);
            $position += $size;

            return $token;
        };
        $expect = function (string $word) use ($next): void {
            if ($next() !== $word) {
                throw new StlException('structure');
            }
        };
        $number = function () use ($next): float {
            $token = $next();
            if ($token === null || ! preg_match(self::NUMBER, $token)) {
                throw new StlException('number');
            }

            return (float) $token;
        };

        $values = [];
        $token = $next();
        // Лимит треугольников не нужен: грань ASCII не короче 86 байт, в MAX_BYTES помещается меньше 305 000.
        while ($token === 'facet') {
            $expect('normal');
            $number();
            $number();
            $number();
            $expect('outer');
            $expect('loop');
            for ($v = 0; $v < 3; $v++) {
                $expect('vertex');
                for ($axis = 0; $axis < 3; $axis++) {
                    $values[] = self::coordinate($number());
                }
            }
            $expect('endloop');
            $expect('endfacet');
            $token = $next();
        }
        if ($token !== 'endsolid') {
            throw new StlException('structure');
        }
        // После endsolid допускается только имя на той же строке.
        $rest = substr($text, (int) strrpos($text, 'endsolid') + strlen('endsolid'));
        if (preg_match('/\n[ \t\n\x0B\f\r]*[^ \t\n\x0B\f\r]/', $rest)) {
            throw new StlException('structure');
        }
        if ($values === []) {
            throw new StlException('triangle_count');
        }

        return $values;
    }

    /**
     * Каждое ребро (пара одинаковых после float32 вершин) должно встречаться ровно дважды.
     *
     * @param  list<float>  $positions
     */
    private function isClosed(array $positions): bool
    {
        $vertexIds = [];
        $ids = [];
        for ($i = 0, $n = count($positions); $i < $n; $i += 3) {
            $key = pack('g3', $positions[$i], $positions[$i + 1], $positions[$i + 2]);
            $ids[] = $vertexIds[$key] ??= count($vertexIds);
        }
        $base = count($vertexIds);
        unset($vertexIds);
        $edges = [];
        for ($t = 0, $n = count($ids); $t < $n; $t += 3) {
            foreach ([[$ids[$t], $ids[$t + 1]], [$ids[$t + 1], $ids[$t + 2]], [$ids[$t + 2], $ids[$t]]] as [$p, $q]) {
                $key = min($p, $q) * $base + max($p, $q);
                $edges[$key] = ($edges[$key] ?? 0) + 1;
            }
        }
        foreach ($edges as $count) {
            if ($count !== 2) {
                return false;
            }
        }

        return true;
    }
}

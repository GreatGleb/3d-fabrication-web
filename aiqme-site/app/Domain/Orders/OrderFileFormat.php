<?php

namespace App\Domain\Orders;

/**
 * Форматы файлов заказа калькулятора (правка 02.11, Г6): белый список расширений, 3D-форматы (печать возможна только для
 * них) и проверка начала файла для форматов без разбора — содержимое не разбирается, отсекается подмена расширения
 * (например, программа .exe под именем .dxf). STL проверяет StlAnalyzer.
 */
final class OrderFileFormat
{
    public const EXTENSIONS = ['stl', 'step', 'stp', 'obj', 'dxf', 'dwg'];

    /** Форматы, которые можно напечатать. */
    public const THREE_D = ['stl', 'step', 'stp', 'obj'];

    /** Расширение из имени файла клиента строчными; не из белого списка — null. */
    public static function extension(string $name): ?string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONS, true) ? $extension : null;
    }

    /**
     * Начало файла соответствует формату: DWG — сигнатура версии «AC» и 4 цифры (AC1032); STEP — «ISO-10303-21;»;
     * DXF — двоичная сигнатура или текст; OBJ — текст. Текст — первые 4 КиБ без нулевых байтов и без сигнатуры
     * исполняемого файла «MZ» / ELF.
     */
    public static function matches(string $path, string $extension): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 4096);
        $text = $head !== '' && ! str_contains($head, "\0") && ! str_starts_with($head, 'MZ') && ! str_starts_with($head, "\x7FELF");

        return match ($extension) {
            'dwg' => preg_match('/^AC\d{4}/', $head) === 1,
            'step', 'stp' => str_starts_with(ltrim($head, "\xEF\xBB\xBF \t\r\n"), 'ISO-10303-21;'),
            'dxf' => str_starts_with($head, "AutoCAD Binary DXF\r\n\x1A\0") || $text,
            'obj' => $text,
            default => false,
        };
    }
}

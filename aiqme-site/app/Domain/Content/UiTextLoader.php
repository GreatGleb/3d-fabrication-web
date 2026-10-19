<?php

namespace App\Domain\Content;

use App\Domain\Content\Models\UiText;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;

/**
 * Загрузчик переводов: строки lang/<locale>/<group>.php, поверх них — значения ui_texts из БД (§4.1 ui_text).
 * Подключён к переводчику Laravel, поэтому __() любого шаблона учитывает правку. Без RU-значения переводчик берёт
 * EN (fallback_locale) — вместе с его значениями из БД (§4.3).
 */
final class UiTextLoader implements Loader
{
    public function __construct(private readonly Loader $files) {}

    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->files->load($locale, $group, $namespace);
        if ($group === '*' || ($namespace !== null && $namespace !== '*')) {
            return $lines;
        }

        foreach ($this->overrides($locale, $group) as $key => $value) {
            Arr::set($lines, substr($key, strlen($group) + 1), $value);
        }

        return $lines;
    }

    /** @return array<string, string> */
    private function overrides(string $locale, string $group): array
    {
        try {
            return UiText::where('locale', $locale)
                ->where('key', 'like', addcslashes($group, '%_\\').'.%')
                ->pluck('value', 'key')
                ->all();
        } catch (QueryException) {
            // До миграций (или без БД) действуют только файлы lang/.
            return [];
        }
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->files->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->files->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->files->namespaces();
    }
}

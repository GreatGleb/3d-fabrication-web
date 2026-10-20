<?php

namespace App\Domain\Content;

use App\Domain\Content\Models\ContentItem;
use Illuminate\Validation\ValidationException;

/**
 * Проверка медиафайла контента (§6.4) — одна для веб-админки (W-08) и Telegram-бота (W-19): размер не больше
 * aiqme.media_max_kb, формат — только по сигнатуре (JPEG, PNG, WebP, MP4; SVG и прочее не принимаются) и подходит
 * коллекции. Возвращает расширение по сигнатуре: имя файла назначает сервер (UUID + это расширение), исходные имя и
 * расширение не используются.
 */
final class MediaFile
{
    private const IMAGE_BRANDS = ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1', 'avif', 'avis'];

    public static function check(string $path, string $collection): string
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size === 0 || $size > self::maxKilobytes() * 1024) {
            self::fail();
        }
        $extension = self::sniff($path);
        if ($extension === null || ! in_array($extension, ContentItem::MEDIA_COLLECTIONS[$collection] ?? [], true)) {
            self::fail();
        }

        return $extension;
    }

    /** Формат файла по первым байтам; расширение и MIME клиента — лишь подсказки (§6.4). */
    public static function sniff(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            // ftyp с основным брендом видео; HEIC/AVIF-картинки (heic, mif1, avif…) — тоже ftyp, но не MP4.
            substr($head, 4, 4) === 'ftyp' && ! in_array(substr($head, 8, 4), self::IMAGE_BRANDS, true) => 'mp4',
            default => null,
        };
    }

    public static function maxKilobytes(): int
    {
        return (int) config('aiqme.media_max_kb');
    }

    private static function fail(): never
    {
        throw ValidationException::withMessages(['file' => __('content.media_invalid', ['max' => intdiv(self::maxKilobytes(), 1024)])]);
    }
}

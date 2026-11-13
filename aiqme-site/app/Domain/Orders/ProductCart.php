<?php

namespace App\Domain\Orders;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Корзина товаров «Pirkti» (правка 05.11, H-08): в сессии, гостю тоже — id товара => количество (1–QUANTITY_MAX).
 * Цена берётся из товара в момент показа и оформления (в сессии её нет). Товар, снятый с публикации на локали или
 * переведённый в «Gauti pasiūlymą», убирается из корзины при показе и оформлении (prune → true — покупателю сообщение).
 * Стоимость доставки товаров — настройка «Pristatymo kaina (prekėms)» (settings products.delivery_cents, по умолчанию 0).
 * «Numatomas terminas» письма клиенту после оплаты (правка 12.11, K-01) — products.delivery_time по языкам, пусто — без строки.
 */
final class ProductCart
{
    public const SESSION = 'cart';

    public const QUANTITY_MAX = 100;

    /** Запись settings с настройками продажи товаров. */
    public const SETTING = 'products';

    public function __construct(private readonly PublishedContent $content) {}

    /** Число товаров в корзине (сумма количеств) — значок в шапке. */
    public function count(): int
    {
        return array_sum(array_map('intval', $this->items()));
    }

    /** Добавляет количество товара (складывается, не больше QUANTITY_MAX). */
    public function add(int $itemId, int $quantity): void
    {
        $items = $this->items();
        $items[$itemId] = min(self::QUANTITY_MAX, (int) ($items[$itemId] ?? 0) + $quantity);
        session()->put(self::SESSION, $items);
    }

    /** Новое количество товара; 0 — удалить. */
    public function set(int $itemId, int $quantity): void
    {
        $items = $this->items();
        if ($quantity < 1) {
            unset($items[$itemId]);
        } elseif (isset($items[$itemId])) {
            $items[$itemId] = min(self::QUANTITY_MAX, $quantity);
        }
        session()->put(self::SESSION, $items);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION);
    }

    /**
     * Позиции корзины на локали: опубликованный товар «Pirkti», количество, цена за единицу и сумма (центы). Остальные
     * позиции удаляются из сессии; $removed — были ли такие.
     *
     * @param-out bool $removed
     *
     * @return Collection<int, array{product: ContentTranslation, quantity: int, unit: int, total: int}>
     */
    public function lines(string $locale, ?bool &$removed = null): Collection
    {
        $items = $this->items();
        $products = $this->content->list(ContentType::Product, $locale)->load('item.media')->keyBy('content_item_id');
        $lines = collect();
        foreach ($items as $id => $quantity) {
            $product = $products->get($id);
            if ($product === null || ! $product->item->isForSale()) {
                continue;
            }
            $lines->put($id, [
                'product' => $product,
                'quantity' => (int) $quantity,
                'unit' => $product->item->salePrice(),
                'total' => (int) $quantity * $product->item->salePrice(),
            ]);
        }
        $removed = $lines->count() !== count($items);
        if ($removed) {
            session()->put(self::SESSION, $lines->map(fn (array $line): int => $line['quantity'])->all());
        }

        return $lines;
    }

    /** Стоимость доставки товаров, центы (настройка «Pristatymo kaina (prekėms)»; по умолчанию 0 €). */
    public static function deliveryCents(): int
    {
        return (int) (Setting::find(self::SETTING)?->value['delivery_cents'] ?? 0);
    }

    public static function saveDeliveryCents(int $cents): void
    {
        self::saveValue('delivery_cents', $cents);
    }

    /** «Numatomas terminas» на языке (правка 12.11, K-01); не задан — null. */
    public static function deliveryTime(string $locale): ?string
    {
        $time = Setting::find(self::SETTING)?->value['delivery_time'][$locale] ?? null;

        return is_string($time) && trim($time) !== '' ? $time : null;
    }

    /** @param  array<string, ?string>  $times  язык => текст (пусто — null) */
    public static function saveDeliveryTime(array $times): void
    {
        self::saveValue('delivery_time', $times);
    }

    private static function saveValue(string $key, mixed $value): void
    {
        $setting = Setting::find(self::SETTING);
        $values = is_array($setting?->value) ? $setting->value : [];
        Setting::updateOrCreate(['key' => self::SETTING], ['value' => [...$values, $key => $value]]);
    }

    /** @return array<int, int> */
    private function items(): array
    {
        $items = session()->get(self::SESSION, []);

        return is_array($items) ? $items : [];
    }
}

<?php

namespace App\Models;

use App\Domain\Orders\PricedLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lunar\Base\Purchasable;

/**
 * Служебная строка заказа (§2.5, §3.1): вид fee — плата за заказ и за файлы (W-12, при сумме 0 не создаётся),
 * delivery — доставка (строка заказа, её добавляет OrderWorkflow::confirmPrice, W-13). Сумма в центах — цена строки Lunar при quantity = 1.
 */
#[Fillable(['order_id', 'kind', 'amount'])]
class OrderCharge extends Model implements Purchasable
{
    use PricedLine;

    public const FEE = 'fee';

    public const DELIVERY = 'delivery';

    public function lineCents(): int
    {
        return $this->amount;
    }

    public function getDescription(): string
    {
        return $this->kind;
    }

    public function getOption(): string
    {
        return $this->kind;
    }

    public function getOptions(): Collection
    {
        return collect(['kind' => $this->kind]);
    }

    public function getIdentifier(): string
    {
        return 'CHARGE-'.$this->id;
    }
}

<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Сообщение по заказу (§1.3, §2.5): kind = admin — сообщение администратора с текстом; agree / question / cancel_request —
 * ответ клиента одной из трёх кнопок (без текста). Ответ только записывается и ничего не меняет в заказе.
 */
#[Fillable(['order_id', 'user_id', 'kind', 'body'])]
class OrderMessage extends Model
{
    public const ADMIN = 'admin';

    public const REPLIES = ['agree', 'question', 'cancel_request'];
}

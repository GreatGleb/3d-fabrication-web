<?php

namespace App\Http\Controllers\Public;

use App\Domain\Forms\Altcha;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Выдача задачи ALTCHA виджету форм (правка 28.10 П3): без ключа — 503, иначе подписанная задача, не кэшируется.
 */
class AltchaController extends Controller
{
    public function __invoke(Altcha $altcha): JsonResponse
    {
        abort_unless($altcha->configured(), 503);

        return response()->json($altcha->challenge()->toArray())
            ->header('Cache-Control', 'no-store, private');
    }
}

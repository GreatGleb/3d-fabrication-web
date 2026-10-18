<?php

use Comparison\Http\WorkspaceController as Workspace;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('/comparison/baskets', [Workspace::class, 'create'])->name('comparison.create');
    Route::get('/comparison/baskets/{basket}', [Workspace::class, 'basket'])->name('comparison.basket');
    Route::post('/comparison/baskets/{basket}/files', [Workspace::class, 'add'])->name('comparison.add');
    Route::post('/comparison/baskets/{basket}/lines/{line}', [Workspace::class, 'change'])->name('comparison.change');
    Route::delete('/comparison/baskets/{basket}/lines/{line}', [Workspace::class, 'remove'])->name('comparison.remove');
    Route::post('/comparison/baskets/{basket}/submit', [Workspace::class, 'submit'])->name('comparison.submit');
    Route::get('/comparison/orders', [Workspace::class, 'orders'])->name('comparison.orders');
    Route::get('/comparison/customers', [Workspace::class, 'customers'])->name('comparison.customers');
    Route::post('/comparison/orders/{order}/cancel', [Workspace::class, 'cancel'])->name('comparison.cancel');
    Route::post('/comparison/payments/{payment}', [Workspace::class, 'checkout'])->name('comparison.checkout');
});
Route::post('/comparison/stripe/webhook', [Workspace::class, 'webhook'])->name('comparison.webhook');

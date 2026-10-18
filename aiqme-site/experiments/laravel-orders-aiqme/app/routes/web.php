<?php

use App\Http\Controllers\ProbeOrderController;
use App\Services\ContentPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('probe-login'))->name('login');
Route::post('/login', function (Request $request) {
    $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
    abort_unless(Auth::attempt($data), 403);
    $request->session()->regenerate();

    return redirect()->route('comparison.orders');
})->middleware('throttle:10,1');
Route::middleware('auth')->group(function () {
    Route::get('/orders/{order}', [ProbeOrderController::class, 'show'])->name('probe.order');
    Route::get('/stl/{part}', [ProbeOrderController::class, 'file'])->name('probe.file');
    Route::post('/orders/{order}/confirm', [ProbeOrderController::class, 'confirm'])->name('probe.confirm');
    Route::post('/orders/{order}/payment', [ProbeOrderController::class, 'payment'])->name('probe.payment');
    Route::post('/orders/{order}/start', [ProbeOrderController::class, 'start'])->name('probe.start');
});

Route::post('/probe-content', function (Request $request, ContentPublisher $publisher) {
    $data = $request->validate(['locale' => 'required|string', 'title' => 'required|string', 'id' => 'nullable|integer', 'revision' => 'sometimes|integer|min:0']);

    return response()->json(['id' => $publisher->publish($request->user(), $data['locale'], $data['title'], $data['id'] ?? null, $data['revision'] ?? 0)]);
})->middleware('auth')->name('probe.content');

require base_path('../../orders-comparison/shared/routes.php');

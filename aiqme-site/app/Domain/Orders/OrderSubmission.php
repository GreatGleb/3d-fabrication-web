<?php

namespace App\Domain\Orders;

use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Pricing\CalculatorSettings;
use App\Domain\Pricing\EstimateService;
use App\Domain\Pricing\StlAnalyzer;
use App\Domain\Pricing\StlException;
use App\Domain\Pricing\StlModel;
use App\Models\OrderCharge;
use App\Models\StlFile;
use App\Models\StlPart;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use RuntimeException;
use Throwable;

/**
 * Отправка заявки на изготовление (§3.1, W-12) — гостем или вошедшим клиентом. Каждый STL проверяет StlAnalyzer, оценку
 * пересчитывает EstimateService по текущим настройкам (клиентская сумма не используется). Файлы сохраняются на диск local
 * под именем UUID, затем в одной транзакции: корзина Lunar (строки StlPart с quantity = 1 и служебная OrderCharge fee, если
 * плата > 0) → заказ через RequestCartValidator, порядковый номер (OrderNumber), order_meta с хешем гостевого токена,
 * состояние production_states в стадии review (W-13) и событие outbox «заявка отправлена» (письмо на info@aiqme.com после commit, канал sheets — pending). Ошибка после записи
 * файлов удаляет их — сирот нет. Повтор токена отправки с теми же данными возвращает тот же заказ (гостю — с новым
 * гостевым токеном, прежний перестаёт действовать), с другими данными — отказ.
 */
final class OrderSubmission
{
    public function __construct(
        private readonly StlAnalyzer $analyzer,
        private readonly CalculatorSettings $settings,
        private readonly EstimateService $estimates,
        private readonly IntegrationEvents $events,
    ) {}

    /**
     * @param  array{submission_token: string, locale: string, contact: array<string, ?string>, delivery: string, address: ?array<string, string>, material: string, infill: int, preferences: array<string, string>, parts: list<array{file: UploadedFile, unit: string, scale: int|float, quantity: int}>}  $data
     */
    public function submit(array $data, ?User $user): SubmittedOrder
    {
        $settings = $this->settings->values();
        $parts = [];
        foreach ($data['parts'] as $index => $part) {
            $name = Str::limit(basename($part['file']->getClientOriginalName()), 200, '');
            try {
                $model = $this->analyzeOneAtATime($part['file']->getRealPath(), "parts.{$index}.file", $name);
            } catch (StlException $exception) {
                throw ValidationException::withMessages(["parts.{$index}.file" => __('calculator.js.'.($exception->reason === 'size' ? 'too_large' : 'invalid'), [
                    'name' => $name,
                    'mb' => intdiv(config('aiqme.calculator.max_file_bytes'), 1024 * 1024),
                ])]);
            }
            $parts[] = [
                ...$part,
                'name' => $name,
                'model' => $model,
                'sha256' => hash_file('sha256', $part['file']->getRealPath()),
                'measurement' => $this->analyzer->measure($model, $part['unit'], $part['scale'], $settings['max_dimension_mm']),
            ];
        }
        $digest = $this->digest($data, $parts, $user);
        if ($repeat = $this->repeat($data['submission_token'], $digest)) {
            return $repeat;
        }

        $estimate = $this->estimates->estimate(
            array_map(fn (array $part): array => ['volume_cm3' => $part['measurement']['volume_cm3'], 'quantity' => $part['quantity']], $parts),
            $data['material'],
            $data['infill'],
        );
        $stored = [];
        try {
            foreach ($parts as $part) {
                $path = $part['file']->storeAs(StlFile::DIRECTORY, Str::uuid().'.stl', 'local');
                if ($path === false) {
                    throw new RuntimeException('STL was not stored.');
                }
                $stored[] = $path;
            }

            return DB::transaction(fn (): SubmittedOrder => $this->createOrder($data, $parts, $stored, $estimate, $digest, $user));
        } catch (UniqueConstraintViolationException $exception) {
            // Та же заявка (тот же токен отправки) только что создана параллельным запросом: ответ — тот же заказ.
            Storage::disk('local')->delete($stored);

            return $this->repeat($data['submission_token'], $digest) ?? throw $exception;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }
    }

    /** Открытый гостевой токен: 32 случайных байта в base64url (§6.2); в БД — только SHA-256. */
    public static function guestToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @param  list<string>  $paths
     * @param  array{lines: list<array{mass_grams: float, cents: int}>, order_fee_cents: int, file_fees_cents: int, total_cents: int, settings: array<string, mixed>}  $estimate
     */

    /**
     * Разбор STL — строго по одному в контейнере (flock; блокировку снимает и падение процесса): файл в 50 МиБ занимает
     * до 450 МБ, а несколько разборов сразу превысили бы память контейнера app. Остальные заявки ждут очереди до
     * aiqme.calculator.analysis_wait_seconds, затем получают просьбу отправить заявку ещё раз.
     */
    private function analyzeOneAtATime(string $path, string $field, string $name): StlModel
    {
        $lock = fopen(storage_path('framework/stl-analysis.lock'), 'c');
        try {
            $deadline = microtime(true) + config('aiqme.calculator.analysis_wait_seconds');
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw ValidationException::withMessages([$field => __('calculator.js.busy', ['name' => $name])]);
                }
                usleep(200_000);
            }

            return $this->analyzer->analyzeFile($path);
        } finally {
            fclose($lock);
        }
    }

    private function createOrder(array $data, array $parts, array $paths, array $estimate, string $digest, ?User $user): SubmittedOrder
    {
        $cart = Cart::create([
            'user_id' => $user?->id,
            'channel_id' => Channel::getDefault()->id,
            'currency_id' => Currency::whereCode('EUR')->firstOrFail()->id,
        ]);
        $files = [];
        foreach ($parts as $index => $part) {
            $files[] = $file = StlFile::create([
                'user_id' => $user?->id,
                'path' => $paths[$index],
                'original_name' => $part['name'],
                'size' => $part['file']->getSize(),
                'sha256' => $part['sha256'],
                'triangles' => $part['model']->triangles,
                'analysis' => [
                    'size' => $part['model']->size,
                    'volume' => $part['model']->volume,
                    'closed' => $part['model']->closed,
                    'analyzer' => StlAnalyzer::VERSION,
                ],
            ]);
            $cart->add(StlPart::create([
                'stl_file_id' => $file->id,
                'parameters' => [
                    'material' => $data['material'],
                    'infill' => $data['infill'],
                    'unit' => $part['unit'],
                    'scale' => $part['scale'],
                    'quantity' => $part['quantity'],
                    ...$data['preferences'],
                ],
                'measurement' => [...$part['measurement'], 'mass_grams' => $estimate['lines'][$index]['mass_grams']],
                'estimate' => $estimate['lines'][$index]['cents'],
            ]), 1);
        }
        $fee = $estimate['order_fee_cents'] + $estimate['file_fees_cents'];
        $charge = $fee > 0 ? OrderCharge::create(['kind' => OrderCharge::FEE, 'amount' => $fee]) : null;
        if ($charge) {
            $cart->add($charge, 1);
        }

        $order = $cart->createOrder();
        $order->update(['placed_at' => now()]);
        StlFile::whereKey(array_column($files, 'id'))->update(['order_id' => $order->id]);
        $charge?->update(['order_id' => $order->id]);
        if ($order->total->value !== $estimate['total_cents']) {
            throw new RuntimeException("Order total {$order->total->value} differs from the estimate {$estimate['total_cents']}.");
        }

        $token = $user ? null : self::guestToken();
        $meta = OrderMeta::create([
            'order_id' => $order->id,
            'number' => (int) $order->reference,
            'guest_token_hash' => $token === null ? null : hash('sha256', $token),
            'submission_token' => $data['submission_token'],
            'submission_digest' => $digest,
            'locale' => $data['locale'],
            'contact' => $data['contact'],
            'delivery' => $data['delivery'],
            'address' => $data['address'],
            'settings' => $estimate['settings'],
        ]);
        ProductionState::create(['order_id' => $order->id]);
        $this->events->record('order.submitted', $order, [
            'kind' => 'order',
            'number' => $meta->number,
            'name' => $data['contact']['name'],
            'email' => $data['contact']['email'],
            'phone' => $data['contact']['phone'],
            'company' => $data['contact']['company'],
            'subject' => (string) $meta->number,
            'summary' => Str::limit(implode(', ', array_column($parts, 'name')), 200),
            'attachments' => count($parts),
            'amount' => number_format($estimate['total_cents'] / 100, 2, '.', ''),
            'admin_url' => route('lt.admin', ['order' => $order->id]),
        ]);

        return new SubmittedOrder($order, $meta, $token);
    }

    /**
     * Повтор токена отправки: другой отпечаток данных — отказ; тот же — тот же заказ, гостю — перевыпуск гостевого токена.
     */
    private function repeat(string $submissionToken, string $digest): ?SubmittedOrder
    {
        return DB::transaction(function () use ($submissionToken, $digest): ?SubmittedOrder {
            $meta = OrderMeta::where('submission_token', $submissionToken)->lockForUpdate()->first();
            if ($meta === null) {
                return null;
            }
            if (! hash_equals($meta->submission_digest, $digest)) {
                throw ValidationException::withMessages(['submission_token' => __('calculator.submit.conflict')]);
            }
            $token = null;
            if ($meta->guest_token_hash !== null) {
                $token = self::guestToken();
                $meta->update(['guest_token_hash' => hash('sha256', $token)]);
            }

            return new SubmittedOrder(Order::findOrFail($meta->order_id), $meta, $token);
        });
    }

    /**
     * Отпечаток данных отправки: отправитель, локаль, контакты, получение, параметры и по каждому файлу — sha256 содержимого,
     * имя, единицы, масштаб и количество.
     *
     * @param  list<array<string, mixed>>  $parts
     */
    private function digest(array $data, array $parts, ?User $user): string
    {
        return hash('sha256', json_encode([
            $user?->id,
            $data['locale'],
            $data['contact'],
            $data['delivery'],
            $data['address'],
            $data['material'],
            $data['infill'],
            $data['preferences'],
            array_map(fn (array $part): array => [$part['sha256'], $part['name'], $part['unit'], (float) $part['scale'], $part['quantity']], $parts),
        ], JSON_THROW_ON_ERROR));
    }
}

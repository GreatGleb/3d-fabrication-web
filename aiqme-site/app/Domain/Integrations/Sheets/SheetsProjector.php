<?php

namespace App\Domain\Integrations\Sheets;

use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Forms\Models\TrainingRegistration;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Orders\OrderWorkflow;
use App\Domain\Payments\Models\PaymentRequest;
use App\Models\CustomerProfile;
use App\Models\StlFile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lunar\Models\Order;
use Lunar\Models\Transaction;

/**
 * Проекция событий outbox в Google Таблицу (§5.1, §5.3, W-18): односторонняя запись, английские заголовки в первой
 * строке, значения строками (RAW: формулы во вводе остаются текстом), деньги — «123.45», неизвестное — пустая строка,
 * время — UTC. Строки сущностей каждый раз собираются из БД, а не из данных события (событие даёт только строку Events).
 * Upsert — по ключу в колонке A (номер заказа, ID заявки, email, public_id запроса, ID события), не по номеру строки:
 * перед записью лист читается, найденная строка перезаписывается, новая — пишется под последней.
 */
final class SheetsProjector
{
    public const SHEETS = [
        'Orders' => ['Order No', 'Created (UTC)', 'Updated', 'Customer', 'Email', 'Phone', 'Company', 'Files', 'Production stage', 'Confirmed price EUR', 'Paid EUR', 'Due EUR', 'Payment state', 'Delivery', 'Tracking', 'Invoice No', 'Admin link'],
        'Requests' => ['Request ID', 'Type', 'Created', 'Name', 'Email', 'Phone', 'Company', 'Service/Training', 'Summary', 'Attachments', 'Admin link'],
        'Contacts' => ['Email', 'Name', 'Phone', 'Company', 'Company code', 'VAT code', 'Registered', 'Last activity'],
        'Payments' => ['Payment request ID', 'Order No', 'Amount EUR', 'State', 'Created', 'Paid at', 'Stripe reference'],
        'Events' => ['Event ID', 'Occurred (UTC)', 'Entity', 'Type', 'Summary', 'Amount EUR'],
    ];

    /** Предел строк на один запрос values:batchUpdate при пересборке (§5.3). */
    public const BATCH_ROWS = 5000;

    public function __construct(private readonly SheetsClient $client, private readonly OrderWorkflow $workflow) {}

    /** Подключены ли Sheets: заданы ключ сервисного аккаунта и ID таблицы (.env). */
    public static function configured(): bool
    {
        return filled(config('aiqme.sheets.key')) && filled(config('aiqme.sheets.spreadsheet_id'));
    }

    /** Ключ сущности события для WithoutOverlapping заданий проекции. */
    public static function entityKey(IntegrationEvent $event): string
    {
        return 'sheets:'.$event->entity_type.':'.$event->entity_id;
    }

    /** Строки листов по событию §5.1 из текущего состояния БД и upsert их одним batchUpdate. */
    public function project(IntegrationEvent $event): void
    {
        $rows = ['Events' => [$this->eventRow($event)]];
        $entity = $event->entity_id;
        $order = null;
        $email = null;
        if ($event->entity_type === 'payment_requests' && ($request = PaymentRequest::find($entity))) {
            $rows['Payments'] = [$this->paymentRow($request)];
            $order = Order::find($request->order_id);
        } elseif ($event->entity_type === 'invoices') {
            $order = Order::find(Invoice::whereKey($entity)->value('order_id'));
        } elseif ($event->entity_type === (new Order)->getTable()) {
            $order = Order::find($entity);
            $email = OrderMeta::where('order_id', $entity)->value('contact')['email'] ?? null;
        } elseif ($event->entity_type === 'contact_requests' && ($request = ContactRequest::find($entity))) {
            $rows['Requests'] = [$this->contactRequestRow($request)];
            $email = $request->email;
        } elseif ($event->entity_type === 'training_registrations' && ($registration = TrainingRegistration::find($entity))) {
            $rows['Requests'] = [$this->trainingRow($registration)];
            $email = $registration->email;
        }
        if ($order !== null) {
            $rows['Orders'] = [$this->orderRow($order)];
        }
        if (filled($email)) {
            $rows['Contacts'] = [$this->contactRow($email)];
        }

        $data = [];
        foreach ($rows as $sheet => $sheetRows) {
            $keys = array_map(fn (array $row): string => (string) ($row[0] ?? ''), $this->client->getValues($this->fullRange($sheet)));
            $data[] = ['range' => $sheet.'!A1', 'values' => [self::SHEETS[$sheet]]];
            $next = max(count($keys), 1) + 1;
            foreach ($sheetRows as $row) {
                $index = array_search($row[0], array_slice($keys, 1), true);
                $data[] = ['range' => $sheet.'!A'.($index === false ? $next++ : $index + 2), 'values' => [$row]];
            }
        }
        $this->client->batchUpdate($data);
    }

    /**
     * Пересборка листов из БД (aiqme:sheets-rebuild): заголовок и все строки пакетами ≤ BATCH_ROWS строк на запрос;
     * строки ниже последней (лишние, ручные) заполняются пустыми значениями в тех же запросах. Писем и событий нет.
     */
    public function rebuild(): void
    {
        foreach (self::SHEETS as $sheet => $headers) {
            $rows = [$headers, ...$this->allRows($sheet)];
            $existing = count($this->client->getValues($this->fullRange($sheet)));
            for ($i = count($rows); $i < $existing; $i++) {
                $rows[] = array_fill(0, count($headers), '');
            }
            foreach (array_chunk($rows, self::BATCH_ROWS) as $chunk => $values) {
                $this->client->batchUpdate([['range' => $sheet.'!A'.($chunk * self::BATCH_ROWS + 1), 'values' => $values]]);
            }
        }
    }

    /** @return list<list<string>> */
    private function allRows(string $sheet): array
    {
        return match ($sheet) {
            'Orders' => Order::whereIn('id', OrderMeta::select('order_id'))->orderBy('id')->get()->map($this->orderRow(...))->all(),
            'Requests' => [
                ...ContactRequest::orderBy('id')->get()->map($this->contactRequestRow(...)),
                ...TrainingRegistration::orderBy('id')->get()->map($this->trainingRow(...)),
            ],
            'Contacts' => DB::query()->fromSub(
                DB::table('order_meta')->selectRaw("lower(contact->>'email') as email")
                    ->union(DB::table('contact_requests')->selectRaw('lower(email)'))
                    ->union(DB::table('training_registrations')->selectRaw('lower(email)')),
                'emails',
            )->whereNotNull('email')->orderBy('email')->pluck('email')->map($this->contactRow(...))->all(),
            'Payments' => PaymentRequest::orderBy('id')->get()->map($this->paymentRow(...))->all(),
            'Events' => IntegrationEvent::orderBy('id')->get()->map($this->eventRow(...))->all(),
        };
    }

    /** @return list<string> */
    private function orderRow(Order $order): array
    {
        $meta = OrderMeta::where('order_id', $order->id)->firstOrFail();
        $state = ProductionState::where('order_id', $order->id)->first();
        $confirmed = $state?->confirmed_at !== null;
        $balance = $this->workflow->balance($order);
        $invoice = Invoice::where('order_id', $order->id)->first();
        $updated = IntegrationEvent::where('data->number', (string) $meta->number)->max('created_at');

        return [
            (string) $meta->number,
            $this->time($order->created_at),
            $this->time($updated === null ? $order->created_at : Carbon::parse($updated)),
            (string) ($meta->contact['name'] ?? ''),
            (string) ($meta->contact['email'] ?? ''),
            (string) ($meta->contact['phone'] ?? ''),
            (string) ($meta->contact['company'] ?? ''),
            (string) StlFile::where('order_id', $order->id)->count(),
            (string) $state?->stage->value,
            $confirmed ? $this->money($balance['total']) : '',
            $this->money($balance['paid']),
            $confirmed ? $this->money($balance['due']) : '',
            $balance['payment_state'],
            (string) $meta->delivery,
            trim($state?->carrier.' '.$state?->tracking_number),
            (string) $invoice?->label(),
            route('lt.admin', ['order' => $order->id]),
        ];
    }

    /** @return list<string> */
    private function contactRequestRow(ContactRequest $request): array
    {
        return [
            'contact-'.$request->id,
            'contact',
            $this->time($request->created_at),
            $request->name,
            $request->email,
            (string) $request->phone,
            (string) $request->company,
            (string) $request->service,
            Str::limit(Str::squish((string) $request->description), 200),
            (string) count($request->attachments ?? []),
            route('lt.admin', ['request' => $request->id]),
        ];
    }

    /** @return list<string> */
    private function trainingRow(TrainingRegistration $registration): array
    {
        return [
            'training-'.$registration->id,
            'training',
            $this->time($registration->created_at),
            $registration->name,
            $registration->email,
            '',
            '',
            trim($registration->training_title.' · '.$registration->training_date, ' ·'),
            Str::limit('Format: '.$registration->format.'; participants: '.$registration->participants, 200),
            '0',
            route('lt.admin.training').'#training-registrations',
        ];
    }

    /**
     * Контакт по email (без учёта регистра): имя, телефон и компания — из самой свежей записи, где они есть (заказ,
     * обращение, регистрация на обучение), у зарегистрированного — сначала профиль; Registered — есть ли пользователь
     * с этим email; Last activity — время самой свежей записи.
     *
     * @return list<string>
     */
    private function contactRow(string $email): array
    {
        $email = Str::lower($email);
        $user = User::with('profile')->whereRaw('lower(email) = ?', [$email])->first();
        $records = collect([
            ...OrderMeta::whereRaw("lower(contact->>'email') = ?", [$email])->get()
                ->map(fn (OrderMeta $meta): array => ['at' => $meta->created_at, ...array_intersect_key($meta->contact, array_flip(['name', 'phone', 'company', 'company_code', 'vat_code']))]),
            ...ContactRequest::whereRaw('lower(email) = ?', [$email])->get()
                ->map(fn (ContactRequest $request): array => ['at' => $request->created_at, 'name' => $request->name, 'phone' => $request->phone, 'company' => $request->company]),
            ...TrainingRegistration::whereRaw('lower(email) = ?', [$email])->get()
                ->map(fn (TrainingRegistration $registration): array => ['at' => $registration->created_at, 'name' => $registration->name]),
        ])->sortByDesc('at')->values();
        /** @var ?CustomerProfile $profile */
        $profile = $user?->profile;
        $latest = fn (string $field): string => (string) ($profile?->{$field} ?: $records->first(fn (array $record): bool => filled($record[$field] ?? null))[$field] ?? '');

        return [
            $email,
            $latest('name') ?: (string) $user?->name,
            $latest('phone'),
            $latest('company'),
            $latest('company_code'),
            $latest('vat_code'),
            $user === null ? 'no' : 'yes',
            $records->isEmpty() ? '' : $this->time($records->first()['at']),
        ];
    }

    /** @return list<string> */
    private function paymentRow(PaymentRequest $request): array
    {
        $capture = Transaction::where('type', 'capture')->where('success', true)->where('meta->request_id', $request->public_id)->first();

        return [
            $request->public_id,
            (string) OrderMeta::where('order_id', $request->order_id)->value('number'),
            $this->money($request->amount),
            $request->state,
            $this->time($request->created_at),
            $capture === null ? '' : $this->time(Carbon::parse($capture->captured_at ?? $capture->created_at)),
            (string) ($capture?->reference ?? $request->stripe_intent_id),
        ];
    }

    /** @return list<string> */
    private function eventRow(IntegrationEvent $event): array
    {
        $entity = match ($event->entity_type) {
            'payment_requests' => 'Payment '.($event->data['public_id'] ?? $event->entity_id),
            'contact_requests' => 'Request contact-'.$event->entity_id,
            'training_registrations' => 'Request training-'.$event->entity_id,
            default => 'Order '.($event->data['number'] ?? ''),
        };

        return [
            (string) $event->id,
            $this->time($event->created_at),
            trim($entity),
            $event->type,
            (string) ($event->data['summary'] ?? ''),
            (string) ($event->data['amount'] ?? ''),
        ];
    }

    private function fullRange(string $sheet): string
    {
        return $sheet.'!A:'.chr(ord('A') + count(self::SHEETS[$sheet]) - 1);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function time(Carbon $time): string
    {
        return $time->copy()->utc()->format('Y-m-d H:i:s');
    }
}

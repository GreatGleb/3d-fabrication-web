<?php

namespace App\Livewire\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\Channel;
use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Forms\Models\TrainingRegistration;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Models\PaymentRequest;
use App\Livewire\Admin\Orders\Index;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Lunar\Models\Order;

/**
 * Метрики Overview (§1.4, W-14): ждут проверки (стадия review), ждут оплаты (есть открытый платёжный запрос), в производстве
 * (in_production и quality_check), новые обращения и регистрации на обучение за 7 дней; последние 10 заказов;
 * предупреждение о неудачных фоновых заданиях — failed_jobs и каналы outbox (письмо, Sheets) в состоянии failed
 * (§2.7, §8.4); «платежи на сверку» (W-28, §3.3 «Аномалии»): запросы с needs_review и события payment_events с
 * result = needs_review без reviewed_at, у каждого действие «разобрано» с записью в журнал; при APP_ENV=demo блока нет
 * (§8.7). Предупреждение «незаполненные реквизиты» добавляет W-17. Только администратору, иначе 404.
 */
class Overview extends Component
{
    public const RECENT_DAYS = 7;

    #[Locked]
    public string $uiLocale;

    public function boot(): void
    {
        abort_unless(auth()->user() && Gate::allows('admin'), 404);
    }

    public function mount(): void
    {
        $this->uiLocale = app()->getLocale();
    }

    public function hydrate(): void
    {
        app()->setLocale($this->uiLocale);
    }

    /** «Разобрано» у запроса: снимает needs_review (state не меняется, §3.3), журнал. */
    public function resolveRequest(int $requestId): void
    {
        $request = PaymentRequest::where('needs_review', true)->findOrFail($requestId);
        $request->update(['needs_review' => false]);
        AuditLog::record('payment.review_resolved', Order::findOrFail($request->order_id), Channel::Web, $this->admin(), [
            'public_id' => $request->public_id,
            'review_reason' => $request->review_reason,
        ]);
    }

    /** «Разобрано» у события needs_review: reviewed_at, журнал (объект — заказ запроса, без запроса — администратор). */
    public function resolveEvent(string $eventId): void
    {
        $event = PaymentEvent::where('result', PaymentEvent::NEEDS_REVIEW)->whereNull('reviewed_at')->findOrFail($eventId);
        $event->update(['reviewed_at' => now()]);
        $request = $event->paymentRequest;
        AuditLog::record('payment.event_reviewed', $request === null ? $this->admin() : Order::findOrFail($request->order_id), Channel::Web, $this->admin(), [
            'event_id' => $event->event_id,
            'type' => $event->type,
        ]);
    }

    public function render(): View
    {
        $stages = fn (ProductionStage ...$stages): int => DB::table('production_states')
            ->whereIn('stage', array_map(fn (ProductionStage $stage): string => $stage->value, $stages))->count();
        $since = now()->subDays(self::RECENT_DAYS);
        $demo = app()->environment('demo');
        $reviewRequests = $demo ? collect() : PaymentRequest::where('needs_review', true)->orderBy('id')->get();
        $reviewEvents = $demo ? collect() : PaymentEvent::with('paymentRequest')->where('result', PaymentEvent::NEEDS_REVIEW)
            ->whereNull('reviewed_at')->orderBy('created_at')->get();
        $orderIds = $reviewRequests->pluck('order_id')->merge($reviewEvents->pluck('paymentRequest.order_id'))->filter();

        return view('admin.overview-panel', [
            'metrics' => [
                'review' => $stages(ProductionStage::Review),
                'payment' => PaymentRequest::whereIn('state', PaymentRequest::OPEN)->distinct()->count('order_id'),
                'production' => $stages(ProductionStage::InProduction, ProductionStage::QualityCheck),
                'enquiries' => ContactRequest::where('created_at', '>=', $since)->count(),
                'registrations' => TrainingRegistration::where('created_at', '>=', $since)->count(),
            ],
            'orders' => Index::rows()->limit(10)->get(),
            'failed' => DB::table('failed_jobs')->count()
                + IntegrationEvent::where('mail_status', 'failed')->orWhere('sheets_status', 'failed')->count(),
            'reviewRequests' => $reviewRequests,
            'reviewEvents' => $reviewEvents,
            'numbers' => OrderMeta::whereIn('order_id', $orderIds)->pluck('number', 'order_id'),
        ]);
    }

    private function admin(): User
    {
        /** @var User */
        return auth()->user();
    }
}

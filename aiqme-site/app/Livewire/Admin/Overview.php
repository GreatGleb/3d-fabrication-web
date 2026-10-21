<?php

namespace App\Livewire\Admin;

use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Forms\Models\TrainingRegistration;
use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Orders\ProductionStage;
use App\Domain\Payments\Models\PaymentRequest;
use App\Livewire\Admin\Orders\Index;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Метрики Overview (§1.4, W-14): ждут проверки (стадия review), ждут оплаты (есть открытый платёжный запрос), в производстве
 * (in_production и quality_check), новые обращения и регистрации на обучение за 7 дней; последние 10 заказов;
 * предупреждение о неудачных фоновых заданиях — failed_jobs и каналы outbox (письмо, Sheets) в состоянии failed
 * (§2.7, §8.4). Предупреждения «платежи на сверку» и «незаполненные реквизиты» добавляют W-28 и W-17. Только
 * администратору, иначе 404.
 */
class Overview extends Component
{
    public const RECENT_DAYS = 7;

    public function boot(): void
    {
        abort_unless(auth()->user() && Gate::allows('admin'), 404);
    }

    public function render(): View
    {
        $stages = fn (ProductionStage ...$stages): int => DB::table('production_states')
            ->whereIn('stage', array_map(fn (ProductionStage $stage): string => $stage->value, $stages))->count();
        $since = now()->subDays(self::RECENT_DAYS);

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
        ]);
    }
}

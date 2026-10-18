<?php

namespace App\Admin;

use App\Models\User;
use App\Services\OrderWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Lunar\Admin\Support\Extending\ViewPageExtension;
use Lunar\Models\Order;

/** Documented hooks; no vendor changes or replacement of Lunar models. */
class ManufacturingOrderExtension extends ViewPageExtension
{
    public function extendInfolistSchema(array $schema): array
    {
        return [Section::make('AIQME manufacturing')
            ->schema([
                TextEntry::make('production.state')->label('Production state'),
                TextEntry::make('production.revision')->label('Agreed price revision'),
                TextEntry::make('aiqme_due')->label('Outstanding EUR cents')
                    ->getStateUsing(fn (Order $record) => app(OrderWorkflow::class)->balance($record)['due']),
                TextEntry::make('stl_files')->label('Private STL files')
                    ->getStateUsing(fn (Order $record) => $record->lines->map(fn ($line) => $line->description.' — '.$line->option)->all()),
            ]), Section::make('Download private STL')->schema(fn (Order $record) => $record->lines->map(fn ($line) => TextEntry::make('stl_'.$line->id)->state($line->description)->url(route('probe.staff-file', $line->purchasable_id)))->all()), ...$schema];
    }

    public function headerActions(array $actions): array
    {
        $page = $this->caller;

        return [
            Action::make('confirmPrice')->label('Confirm agreed price')
                ->schema(fn () => [
                    ...$page->getRecord()->lines->map(fn ($line) => TextInput::make('p_'.$line->id)->label($line->description.' unit EUR cents')->numeric()->integer()->minValue(1)->required()->default($line->unit_price->value))->all(),
                    TextInput::make('reason')->required(), TextInput::make('agreement')->required(),
                ])
                ->action(function (array $data) use ($page) {
                    $order = $page->getRecord();
                    $prices = $order->lines->mapWithKeys(fn ($line) => [$line->id => (int) $data['p_'.$line->id]])->all();
                    app(OrderWorkflow::class)->confirmPrice($this->admin(), $order->id, $prices, $data['reason'], $data['agreement']);
                }),
            Action::make('requestPayment')->label('Request amount (local only)')
                ->schema([TextInput::make('amount')->label('EUR cents')->numeric()->integer()->minValue(1)->required()])
                ->action(fn (array $data) => app(OrderWorkflow::class)->requestPayment($this->admin(), $page->getRecord()->id, (int) $data['amount'])),
            Action::make('startProduction')->label('Start production without payment requirement')
                ->action(fn () => app(OrderWorkflow::class)->startProduction($this->admin(), $page->getRecord()->id)),
        ];
    }

    private function admin(): User
    {
        $user = User::whereEmail(auth('staff')->user()->email)->firstOrFail();
        abort_unless($user->is_admin, 403);

        return $user;
    }
}

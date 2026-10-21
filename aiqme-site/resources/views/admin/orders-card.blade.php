{{-- Карточка заказа (§1.4 Orders, W-14) в разметке adminOrder() прототипа: заголовок с номером и стадией, «Files & production
     settings», notice проверки и кнопки; ниже — разделы панелей: цена, оплата, производство и трек, сообщения и ответы,
     гостевая ссылка, отмена, история. Не переносятся (D-21, §1.4): флажок «demo approval», упаковка и ярлык, «Customer
     view» демо-кабинета, демо-тексты листа задания и его JSON. STL — только ссылки защищённой выдачи (route stl.download). --}}
@php
    $locale = app()->getLocale();
    $money = fn (int $cents): string => \Illuminate\Support\Number::currency($cents / 100, in: 'EUR', locale: $locale);
    $stage = $state->stage;
    $open = ! in_array($stage, [\App\Domain\Orders\ProductionStage::Completed, \App\Domain\Orders\ProductionStage::Cancelled], true);
    $next = $stage->next($pickup);
    $lineLabel = fn ($line): string => match (true) {
        $line->purchasable instanceof \App\Models\StlPart => $line->purchasable->file->original_name,
        $line->purchasable->kind === \App\Models\OrderCharge::DELIVERY => __('admin.orders.delivery'),
        default => __('calculator.js.order_fee'),
    };
    $settings = ['color' => 'colors', 'quality' => 'qualities', 'layer' => 'layers', 'finish' => 'finishes', 'term' => 'terms'];
    $parameters = function (array $p) use ($settings): string {
        $values = [$p['material'], __('calculator.settings.infill').' '.$p['infill'].'%'];
        foreach ($settings as $key => $options) {
            if (isset($p[$key])) {
                $values[] = __('calculator.settings.'.$key).': '.(__('calculator.settings.'.$options)[$p[$key]] ?? $p[$key]);
            }
        }

        return implode(' / ', $values);
    };
@endphp
<div class="panel" id="order-card">
    <div class="status-title"><h2>{{ $meta->number }}</h2><span class="tag blue">{{ __('admin.orders.stages.'.$stage->value) }}</span></div>
    <x-status :message="$status" />
    @error('workflow')<div class="notice error" role="alert">{{ $message }}</div>@enderror
    @if ($confirming)
        <div class="notice warning" role="alert" id="order-confirm">
            {{ __('admin.orders.confirm.'.$confirming, ['due' => $money($balance['due'])]) }}
            <div class="buttons">
                <button class="btn small" type="button" wire:click="{{ ['cancel' => 'cancelOrder', 'change' => 'changePrice', 'start' => 'startProduction'][$confirming] }}">{{ __('admin.orders.confirm.yes') }}</button>
                <button class="btn small secondary" type="button" wire:click="back">{{ __('admin.orders.confirm.no') }}</button>
            </div>
        </div>
    @endif
    <p>{{ __('admin.orders.files') }}: {{ $parts->map(fn ($line) => $line->purchasable->file->original_name)->join(', ') }}</p>
    <div class="notice">{{ __('admin.orders.review_notice') }}</div>
    <div class="buttons">
        @if ($stage === \App\Domain\Orders\ProductionStage::Confirmed)
            <button class="btn small" type="button" wire:click="startProduction">{{ __('admin.orders.next') }}: {{ __('admin.orders.stages.in_production') }}</button>
        @elseif ($open && $next && $stage !== \App\Domain\Orders\ProductionStage::Review)
            <button class="btn small" type="button" wire:click="advance('{{ $next->value }}')">{{ __('admin.orders.next') }}: {{ __('admin.orders.stages.'.$next->value) }}</button>
        @endif
        <button class="btn small secondary" type="button" wire:click="toggleJob" aria-expanded="{{ $showJob ? 'true' : 'false' }}" aria-controls="bambu-job">{{ __('admin.orders.job') }}</button>
    </div>

    @if ($showJob)
        <div class="inset gap" id="bambu-job">
            <h3>{{ __('admin.orders.job_heading') }}</h3>
            <p>{{ $meta->number }} · Bambu Studio</p>
            <x-table>
                <thead><tr><th>{{ __('admin.orders.file') }}</th><th>{{ __('admin.orders.quantity') }}</th><th>{{ __('admin.orders.parameters') }}</th></tr></thead>
                <tbody>
                    @foreach ($parts as $line)
                        @php($part = $line->purchasable)
                        <tr wire:key="job-{{ $line->id }}">
                            <td><a class="text-link" href="{{ route('stl.download', $part->file) }}">{{ $part->file->original_name }}</a></td>
                            <td>{{ $part->parameters['quantity'] }}</td>
                            <td>{{ $parameters($part->parameters) }} / {{ __('admin.orders.unit') }}: {{ $part->parameters['unit'] }} / {{ __('admin.orders.scale') }} {{ $part->parameters['scale'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
            <div class="notice gap">{{ __('admin.orders.job_notice') }}</div>
        </div>
    @endif

    <h3 class="gap">{{ __('admin.orders.files') }}</h3>
    <x-table>
        <thead><tr><th>{{ __('admin.orders.file') }}</th><th>{{ __('admin.orders.parameters') }}</th><th>{{ __('admin.orders.measurement') }}</th><th>{{ __('admin.orders.estimate') }}</th></tr></thead>
        <tbody>
            @foreach ($parts as $line)
                @php($part = $line->purchasable)
                <tr wire:key="part-{{ $line->id }}">
                    <td>{{ $part->file->original_name }}<br><a class="text-link" href="{{ route('stl.download', $part->file) }}">{{ __('admin.orders.download') }}</a></td>
                    <td>{{ __('admin.orders.quantity') }}: {{ $part->parameters['quantity'] }}<br>{{ $parameters($part->parameters) }}<br>{{ __('admin.orders.unit') }}: {{ $part->parameters['unit'] }} · {{ __('admin.orders.scale') }} {{ $part->parameters['scale'] }}</td>
                    <td>
                        {{ implode(' × ', array_map(fn ($mm) => number_format($mm, 1, '.', ''), $part->measurement['dimensions_mm'])) }} mm<br>
                        {{ number_format($part->measurement['volume_cm3'], 2, '.', '') }} cm³ · {{ number_format($part->measurement['mass_grams'], 2, '.', '') }} g
                        @foreach ($part->measurement['warnings'] as $warning)
                            <br><small>{{ $warning === 'open_mesh' ? __('calculator.js.open_mesh', ['name' => $part->file->original_name]) : __('calculator.js.exceeds', ['name' => $part->file->original_name, 'mm' => $meta->settings['max_dimension_mm'] ?? '']) }}</small>
                        @endforeach
                    </td>
                    <td>{{ $money($part->estimate) }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-table>

    <h3 class="gap">{{ $customer ? __('admin.orders.customer') : __('admin.orders.guest') }}</h3>
    <p>
        {{ $meta->contact['name'] ?? '' }} · {{ $meta->contact['email'] ?? '' }} · {{ $meta->contact['phone'] ?? '' }}
        @foreach (['company', 'company_code', 'vat_code'] as $field)
            @if (filled($meta->contact[$field] ?? null)) · {{ $meta->contact[$field] }} @endif
        @endforeach
        <br>{{ __('admin.orders.delivery_method') }}: {{ __('calculator.checkout.deliveries.'.$meta->delivery) }}
        @if ($meta->address)
            <br>{{ __('admin.orders.address') }}: {{ implode(', ', array_filter([$meta->address['address'] ?? null, $meta->address['city'] ?? null, $meta->address['postcode'] ?? null, isset($meta->address['country']) ? __('calculator.countries.'.$meta->address['country']) : null])) }}
        @endif
        @if ($customer)
            <br><a class="text-link" href="{{ route($locale.'.admin.customers', ['customer' => $customer->id]) }}">{{ __('admin.orders.customer_card') }}: {{ $customer->name }}</a>
        @endif
    </p>

    <form class="inset gap" id="order-price-form" wire:submit="{{ $stage === \App\Domain\Orders\ProductionStage::Review ? 'confirmPrice' : 'changePrice' }}">
        <h3>{{ __('admin.orders.price') }}</h3>
        <div class="grid two form-grid">
            @foreach ($priced as $line)
                <x-field :name="'prices.'.$line->id" type="number" step="0.01" min="0" :label="$lineLabel($line).', €'" :value="$prices[$line->id] ?? ''" wire:model="prices.{{ $line->id }}" wire:key="price-{{ $line->id }}" />
            @endforeach
            <x-field name="delivery" type="number" step="0.01" min="0" :label="__('admin.orders.delivery').', €'" :value="$delivery" wire:model="delivery" />
        </div>
        @if (! $deliveryLine)<p><small>{{ __('admin.orders.delivery_hint') }}</small></p>@endif
        @if ($stage === \App\Domain\Orders\ProductionStage::Review)
            <x-button type="submit" small>{{ __('admin.orders.confirm_price') }}</x-button>
        @elseif ($stage === \App\Domain\Orders\ProductionStage::Confirmed)
            <div class="grid two form-grid">
                <x-field name="reason" :label="__('admin.orders.reason')" :value="$reason" wire:model="reason" />
                <x-field name="agreement" :label="__('admin.orders.agreement')" :value="$agreement" wire:model="agreement" />
            </div>
            <x-button type="submit" small variant="secondary">{{ __('admin.orders.change_price') }}</x-button>
        @endif
    </form>

    <div class="inset gap" id="order-payment">
        <h3>{{ __('admin.orders.payment') }}</h3>
        <div class="summary-row"><span>{{ __('admin.orders.total') }}</span><b>{{ $money($balance['total']) }}</b></div>
        <div class="summary-row"><span>{{ __('admin.orders.paid') }}</span><b>{{ $money($balance['paid']) }}</b></div>
        <div class="summary-row"><span>{{ __('admin.orders.due') }}</span><b>{{ $money($balance['due']) }}</b></div>
        <p><span class="tag">{{ __('admin.orders.payment_states.'.$balance['payment_state']) }}</span></p>
        <h3 class="gap">{{ __('admin.orders.requests') }}</h3>
        <x-table>
            <tbody>
                @forelse ($requests as $request)
                    <tr wire:key="request-{{ $request->id }}">
                        <td>{{ $request->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                        <td>{{ $money($request->amount) }}</td>
                        <td>{{ __('admin.orders.request_states.'.$request->state) }}</td>
                        <td>
                            @if ($request->state === \App\Domain\Payments\Models\PaymentRequest::PENDING && $request->stripe_intent_id === null)
                                <button class="btn small secondary" type="button" wire:click="cancelRequest({{ $request->id }})">{{ __('admin.orders.cancel_request') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td>{{ __('admin.orders.no_requests') }}</td></tr>
                @endforelse
            </tbody>
        </x-table>
        @if ($state->confirmed_at && $stage !== \App\Domain\Orders\ProductionStage::Cancelled)
            <form class="gap" id="order-request-form" wire:submit="requestPayment">
                <x-field name="amount" type="number" step="0.01" min="0.5" :label="__('admin.orders.amount')" :value="$amount" wire:model="amount" />
                <x-button type="submit" small>{{ __('admin.orders.request') }}</x-button>
            </form>
        @endif
    </div>

    @if (! $pickup && $open)
        <form class="inset gap" id="order-tracking-form" wire:submit="setTracking">
            <h3>{{ __('admin.orders.production') }}</h3>
            <div class="grid two form-grid">
                <x-field name="carrier" :label="__('admin.orders.carrier')" :value="$carrier" maxlength="255" wire:model="carrier" />
                <x-field name="trackingNumber" :label="__('admin.orders.tracking')" :value="$trackingNumber" maxlength="255" wire:model="trackingNumber" />
            </div>
            <x-button type="submit" small variant="secondary">{{ __('admin.orders.save_tracking') }}</x-button>
        </form>
    @elseif ($state->tracking_number)
        <p class="gap">{{ __('admin.orders.carrier') }}: {{ $state->carrier }} · {{ __('admin.orders.tracking') }}: {{ $state->tracking_number }}</p>
    @endif

    <div class="inset gap" id="order-messages">
        <h3>{{ __('admin.orders.messages') }}</h3>
        @forelse ($messages as $item)
            <p wire:key="message-{{ $item->id }}">
                <small>{{ $item->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</small><br>
                @if ($item->kind === \App\Domain\Orders\Models\OrderMessage::ADMIN)
                    <strong>{{ __('admin.orders.from_admin') }}:</strong> <span style="white-space:pre-line">{{ $item->body }}</span>
                @else
                    <strong>{{ __('admin.orders.customer_response') }}:</strong> {{ __('admin.orders.replies.'.$item->kind) }}
                @endif
            </p>
        @empty
            <p>{{ __('admin.orders.no_messages') }}</p>
        @endforelse
        <form id="order-message-form" wire:submit="postMessage">
            <x-field name="message" type="textarea" :label="__('admin.orders.message')" :value="$message" maxlength="5000" wire:model="message" />
            <x-button type="submit" small variant="secondary">{{ __('admin.orders.send') }}</x-button>
        </form>
    </div>

    @if (! $customer && $meta->guest_token_hash)
        <div class="inset gap" id="order-guest-link">
            <h3>{{ __('admin.orders.guest_link') }}</h3>
            @if ($guestLink)
                <div class="notice warning" role="status">{{ __('admin.orders.guest_link_once') }}<br><a class="text-link" href="{{ $guestLink }}" id="guest-link" style="overflow-wrap:anywhere">{{ $guestLink }}</a></div>
            @else
                <p>{{ __('admin.orders.guest_link_hint') }}</p>
            @endif
            <button class="btn small secondary" type="button" wire:click="reissueGuestLink">{{ __('admin.orders.reissue') }}</button>
        </div>
    @endif

    @if ($open)
        <form class="inset gap" id="order-cancel-form" wire:submit="cancelOrder">
            <h3>{{ __('admin.orders.cancel') }}</h3>
            <x-field name="cancelReason" :label="__('admin.orders.cancel_reason')" :value="$cancelReason" wire:model="cancelReason" />
            <x-button type="submit" small variant="secondary">{{ __('admin.orders.cancel') }}</x-button>
        </form>
    @elseif ($state->cancel_reason)
        <p class="gap">{{ __('admin.orders.cancelled_reason', ['reason' => $state->cancel_reason]) }}</p>
    @endif

    <h3 class="gap">{{ __('admin.orders.history') }}</h3>
    <x-table>
        <tbody>
            @foreach ($revisions as $revision)
                <tr wire:key="revision-{{ $revision->id }}">
                    <td>{{ $revision->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                    <td>{{ __('admin.orders.revision', ['revision' => $revision->revision]) }}</td>
                    <td>{{ $money(array_sum($revision->after)) }}@if ($revision->reason) · {{ $revision->reason }} · {{ $revision->agreement }}@endif</td>
                </tr>
            @endforeach
            @foreach ($history as $entry)
                <tr wire:key="history-{{ $entry->id }}">
                    <td>{{ $entry->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                    <td>{{ trans()->has('admin.audit.events.'.$entry->event) ? __('admin.audit.events.'.$entry->event) : $entry->event }}</td>
                    <td>{{ collect($entry->properties)->except(['channel', 'message_id'])->map(fn ($value, $name) => $name.': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)))->join(' · ') }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-table>
</div>

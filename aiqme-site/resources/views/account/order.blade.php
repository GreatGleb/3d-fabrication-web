{{-- Карточка заказа для клиента (§1.3, W-15) — разметка orderDetail() прототипа: page-top «Order progress», status-title
     с номером и стадией, timeline стадий, решение по сообщению администратора (три ответа), «Order contents» и «Payment &
     delivery», кнопки. Одна и та же в кабинете (владелец, $reorder) и на гостевой странице по секретной ссылке (§6.2).
     Не переносятся (D-21): «DEMONSTRATION ORDER», notice об имитации оплаты, «Demo completed», «Demo payable total»,
     «Prepaid collection», «View demo invoice», «View the staff workflow». Кнопка оплаты открытых запросов — страница
     оплаты W-16 (гостю — по праву в сессии), счета — W-17. STL — только защищённая выдача (route stl.download), токена в адресах файлов нет. --}}
@php
    $locale = app()->getLocale();
    $money = fn (int $cents): string => \Illuminate\Support\Number::currency($cents / 100, in: 'EUR', locale: $locale);
    $date = fn ($time): string => \Illuminate\Support\Carbon::parse($time)->timezone(config('app.timezone'))->format('Y-m-d');
    $cancelled = $state->stage === \App\Domain\Orders\ProductionStage::Cancelled;
    $lastAdmin = $messages->where('kind', \App\Domain\Orders\Models\OrderMessage::ADMIN)->last();
@endphp
<x-layout :title="__('account.order.title')" :description="__('calculator.order.title', ['number' => $meta->number])" noindex>
    <x-page-top :title="__('account.order.title')" />
    <div class="wrap page-content">
        <div class="panel" id="customer-order" data-order-number="{{ $meta->number }}">
            <div class="status-title">
                <div><div class="eyebrow">{{ __('calculator.eyebrow') }}</div><h2>{{ __('calculator.order.title', ['number' => $meta->number]) }}</h2></div>
                <span class="tag blue" data-stage="{{ $state->stage->value }}">{{ __('admin.orders.stages.'.$state->stage->value) }}</span>
            </div>
            <x-status class="gap" />
            @if ($cancelled)
                <div class="notice warning gap">{{ __('admin.orders.cancelled_reason', ['reason' => $state->cancel_reason]) }}</div>
            @else
                <div class="timeline">
                    @foreach ($stages as $index => $stage)
                        <div @class(['active' => $index <= $current])><b>{{ $index <= $current ? '✓' : '○' }} {{ __('admin.orders.stages.'.$stage->value) }}</b><small>{{ $index === $current ? __('account.order.current') : ($index < $current ? __('account.order.done') : __('account.order.pending')) }}</small></div>
                    @endforeach
                </div>
            @endif

            @foreach ($messages as $message)
                @if ($message->kind === \App\Domain\Orders\Models\OrderMessage::ADMIN)
                    @php($replies = $messages->where('id', '>', $message->id)->takeUntil(fn ($next) => $next->kind === \App\Domain\Orders\Models\OrderMessage::ADMIN))
                    <div class="notice warning gap" data-admin-message="{{ $message->id }}">
                        <h3>{{ __('account.order.decision') }}</h3>
                        <p>{{ $message->body }}</p>
                        @foreach ($replies as $reply)
                            <p data-reply="{{ $reply->kind }}"><b>{{ __('admin.orders.replies.'.$reply->kind) }}</b> · {{ $date($reply->created_at) }}</p>
                        @endforeach
                        @if ($replies->isNotEmpty())
                            <p>{{ __('account.order.recorded') }}</p>
                        @endif
                        @if ($message->is($lastAdmin))
                            <form class="buttons gap" method="post" action="{{ route($locale.'.order.reply', $meta->number) }}" id="order-reply">
                                @csrf
                                @foreach (\App\Domain\Orders\Models\OrderMessage::REPLIES as $kind)
                                    <button @class(['btn small', 'secondary' => $kind !== 'agree']) type="submit" name="kind" value="{{ $kind }}">{{ __('admin.orders.replies.'.$kind) }}</button>
                                @endforeach
                            </form>
                        @endif
                    </div>
                @endif
            @endforeach

            <div class="grid two gap">
                <div>
                    <h3>{{ __('calculator.order.contents') }}</h3>
                    @foreach ($parts as $line)
                        @php($part = $line->purchasable)
                        <div class="summary-row"><a class="text-link" href="{{ route('stl.download', $part->file->id) }}">{{ $part->file->original_name }}</a><b>× {{ $part->parameters['quantity'] }}</b></div>
                    @endforeach
                </div>
                <div id="order-payment">
                    <h3>{{ __('account.order.payment_delivery') }}</h3>
                    @if ($state->confirmed_at)
                        <div class="summary-row"><span>{{ __('admin.orders.total') }}</span><b data-total>{{ $money($balance['total']) }}</b></div>
                        <div class="summary-row"><span>{{ __('admin.orders.paid') }}</span><b data-paid>{{ $money($balance['paid']) }}</b></div>
                        <div class="summary-row"><span>{{ __('admin.orders.due') }}</span><b data-due>{{ $money($balance['due']) }}</b></div>
                    @else
                        <div class="summary-row"><span>{{ __('calculator.summary.total') }}</span><b data-total>{{ $money($balance['total']) }}</b></div>
                        <p class="legal-note">{{ __('calculator.summary.note') }}</p>
                    @endif
                    <p><span class="tag">{{ __('admin.orders.payment_states.'.$balance['payment_state']) }}</span></p>
                    @if ($requests->isNotEmpty())
                        <h3 class="gap">{{ __('admin.orders.requests') }}</h3>
                        @foreach ($requests as $request)
                            <div class="summary-row" data-payment-request><span>{{ __('admin.orders.request_states.'.$request->state) }}</span><b>{{ $money($request->amount) }}</b></div>
                            {{-- Кнопка оплаты (W-16): открытый запрос, кроме отменяемого; в демо-сборке блоков оплаты нет (§8.7). --}}
                            @if ($request->state !== \App\Domain\Payments\Models\PaymentRequest::CANCELLING && ! app()->environment('demo'))
                                <x-button :href="route($locale.'.checkout', $request->public_id)" small arrow data-checkout-link>{{ __('checkout.proceed') }}</x-button>
                            @endif
                        @endforeach
                    @endif
                    @if ($payments->isNotEmpty())
                        <h3 class="gap">{{ __('account.order.payments') }}</h3>
                        @foreach ($payments as $payment)
                            <div class="summary-row" data-transaction="{{ $payment->type }}"><span>{{ $date($payment->captured_at ?? $payment->created_at) }} · {{ __('account.order.transactions.'.$payment->type) }}</span><b>{{ $payment->type === 'refund' ? '−' : '' }}{{ $money($payment->amount->value) }}</b></div>
                        @endforeach
                    @endif
                    <p class="gap">{{ __('calculator.checkout.deliveries.'.$meta->delivery) }}
                        @if ($meta->address)
                            <br>{{ implode(', ', array_filter([$meta->address['address'] ?? null, $meta->address['city'] ?? null, $meta->address['postcode'] ?? null, isset($meta->address['country']) ? __('calculator.countries.'.$meta->address['country']) : null])) }}
                        @endif
                    </p>
                    @if ($meta->delivery !== 'pickup')
                        <p data-tracking>{{ $state->tracking_number ? __('account.order.tracking').': '.$state->carrier.' '.$state->tracking_number : __('account.order.tracking_pending') }}</p>
                    @endif
                </div>
            </div>
            @if ($reorder)
                <div class="buttons gap">
                    <a class="btn secondary" href="{{ $reorder }}" data-reorder>{{ __('account.order.reorder') }}</a>
                </div>
            @endif
        </div>
    </div>
</x-layout>

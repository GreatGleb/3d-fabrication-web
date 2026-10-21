{{-- Invoices: пустое состояние; список выпущенных PDF-счетов наполняет W-17. Подпись прототипа о макетах превью
     («Preview layouts only…») не переносится: в рабочем кабинете превью нет. --}}
<div id="account-invoices">
    <h2>{{ __('account.invoices.heading') }}</h2>
    <x-empty class="gap" :title="__('account.invoices.empty')" icon="cart" />
</div>

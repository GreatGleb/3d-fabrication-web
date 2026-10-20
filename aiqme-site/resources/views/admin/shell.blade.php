{{-- Оболочка админки (§1.4, W-08) в оформлении прототипа: верх страницы, меню вкладок (side-nav) и область вкладки.
     Не переносятся (D-21, §1.4): вводный текст «Demo administration…», notice прототипа, ярлык «ADMINISTRATION DEMO»
     и переключатель ролей. В меню — только созданные вкладки (остальные добавляют свои задачи). Выход — POST с CSRF. --}}
@php($locale = app()->getLocale())
<x-layout :title="__('account.admin.title')" :description="__('account.admin.title')" :canonical="route($locale.'.admin'.($tab === 'overview' ? '' : '.'.$tab))"
    :languages="['lt' => route('lt.admin'.($tab === 'overview' ? '' : '.'.$tab)), 'en' => route('en.admin'.($tab === 'overview' ? '' : '.'.$tab))]" noindex>
    <x-page-top :title="__('account.admin.title')" />
    <div class="wrap page-content">
        <div class="admin-layout">
            <nav class="side-nav" aria-label="{{ __('admin.menu') }}">
                @foreach (\App\Http\Controllers\Admin\AdminController::TABS as $name => $icon)
                    <a @class(['active' => $name === $tab]) href="{{ route($locale.'.admin.'.$name) }}" @if ($name === $tab) aria-current="page" @endif><x-line-icon :name="$icon" /> {{ __('admin.tabs.'.$name) }}</a>
                @endforeach
                <button class="btn small secondary" type="submit" form="logout-form">{{ __('account.logout') }}</button>
            </nav>
            <div id="admin-main">
                {{ $slot }}
            </div>
        </div>
        <form id="logout-form" method="post" action="{{ route($locale.'.account.logout') }}" hidden>@csrf</form>
    </div>
</x-layout>

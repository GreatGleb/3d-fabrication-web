{{-- Подвал прототипа (светлый, §7.1). Группы служебных ссылок прототипа и демо-подписи нет (D-21). --}}
@php($locale = app()->getLocale())
<footer class="footer">
    <div class="wrap">
        <div class="footer-top">
            <div>
                <a class="logo" href="{{ route($locale.'.home') }}"><img src="{{ asset('images/logo-dark.png') }}" alt="AIQME"></a>
                <p>{{ __('shell.footer.slogan') }}</p>
            </div>
            <div>
                <h4>{{ __('shell.footer.solutions') }}</h4>
                @foreach (['services', 'products', 'calculator', 'training'] as $page)
                    <a href="{{ route($locale.'.'.$page) }}">{{ __('shell.footer.'.$page) }}</a>
                @endforeach
            </div>
            <div>
                <h4>{{ __('shell.footer.know_us') }}</h4>
                @foreach (['about', 'projects', 'library', 'contact'] as $page)
                    <a href="{{ route($locale.'.'.$page) }}">{{ __('shell.footer.'.$page) }}</a>
                @endforeach
            </div>
        </div>
        <div class="footer-bottom">
            <span>© {{ now()->year }} AIQME</span>
            <span>
                @foreach (['delivery', 'returns', 'privacy', 'cookies'] as $page)
                    <a style="display:inline;margin-left:14px;font-size:10px" href="{{ route($locale.'.'.$page) }}">{{ __('shell.footer.'.$page) }}</a>
                @endforeach
            </span>
        </div>
    </div>
</footer>

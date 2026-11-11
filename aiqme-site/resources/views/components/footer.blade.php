{{-- Подвал прототипа (светлый, §7.1). Группы служебных ссылок прототипа и демо-подписи нет (D-21). Логотип — $logo из layout (D-43). --}}
@props(['logo'])
@php($locale = app()->getLocale())
<footer class="footer">
    <div class="wrap">
        <div class="footer-top">
            <div>
                <a class="logo" href="{{ route($locale.'.home') }}"><img src="{{ $logo }}" alt="AIQME"></a>
                {{-- Правка 11.11, J-01: девиз одной строкой по ширине логотипа (размер шрифта подбирает resources/js/footer-slogan.js). --}}
                <p class="footer-slogan">{{ __('shell.footer.slogan') }}</p>
                {{-- Соцсети (правка 06.11, «правки 5»): ссылки и значки заказчика, config aiqme.social. --}}
                <div class="footer-social">
                    @foreach (config('aiqme.social') as $network => $social)
                        <a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $social['label'] }}" title="{{ $social['label'] }}"><img src="{{ asset('icons/social/'.$network.'.svg') }}" alt="" width="36" height="36"></a>
                    @endforeach
                </div>
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

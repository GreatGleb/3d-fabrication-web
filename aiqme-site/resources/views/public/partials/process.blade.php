{{-- Процесс — общий блок главной (page_text home, §4.1): тот же текст на главной и страницах услуг. --}}
<div class="process">
    @foreach ($home->field('process_steps', []) as $step)
        <div><strong>{{ sprintf('%02d', $loop->iteration) }}</strong><h3>{{ $step['title'] }}</h3><p>{{ $step['text'] }}</p></div>
    @endforeach
</div>

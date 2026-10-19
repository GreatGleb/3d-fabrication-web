{{-- FAQ (§1.2, §4.1): группы услуг в порядке услуг, затем general; без группы services и без копий. --}}
@php($locale = app()->getLocale())
<x-layout :title="$seoTitle" :description="$description" :canonical="route($locale.'.faq')" :languages="$languages" :noindex="$locale === 'ru'">
    <x-page-top :title="$heading" />
    <div class="wrap page-content article">
        @forelse ($questions as $question)
            <details class="faq"><summary>{{ $question->title }}</summary><p>{{ $question->field('answer') }}</p></details>
        @empty
            <x-placeholder />
        @endforelse
    </div>
</x-layout>

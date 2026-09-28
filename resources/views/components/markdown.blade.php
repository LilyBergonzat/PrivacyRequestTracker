@props(['content', 'fillPlaceholders' => false])

<div {{ $attributes->class('prose prose-sm dark:prose-invert max-w-none') }}
     @if ($fillPlaceholders)
         x-data="placeholderFill"
         wire:ignore
         data-copy-label="{{ __('Copy') }}"
         data-copied-label="{{ __('Copied!') }}"
     @endif
>
    {!! Str::markdown($content ?? '', ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
</div>

@props(['date', 'withTime' => false])

@if ($date)
    <time datetime="{{ $date->toIso8601String() }}">
        {{ $date->setTimezone(config('privacy.timezone'))->isoFormat($withTime ? 'LLL' : 'LL') }}
    </time>
@endif

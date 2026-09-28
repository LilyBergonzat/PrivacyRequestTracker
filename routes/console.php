<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('privacy:deadlines')
    ->dailyAt('08:00')
    ->timezone(config('privacy.timezone'))
    ->withoutOverlapping();

Schedule::command('privacy:purge')
    ->dailyAt('03:00')
    ->timezone(config('privacy.timezone'))
    ->withoutOverlapping();

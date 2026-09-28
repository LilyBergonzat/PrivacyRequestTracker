<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified', '2fa'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('requests/new', 'pages::requests.create')->name('requests.create');
    Route::livewire('requests/lookup', 'pages::requests.lookup')->name('requests.lookup');
    Route::livewire('requests/{privacyRequest:reference}', 'pages::requests.show')->name('requests.show');
});

require __DIR__ . '/settings.php';
require __DIR__ . '/admin.php';

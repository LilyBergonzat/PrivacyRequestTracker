<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Models\PrivacyRequest;
use App\Services\ActivityLogger;
use App\Services\RequestRecordExporter;

Route::middleware(['auth', 'verified', '2fa', 'can:admin'])->prefix('admin')->name('admin.')->group(
    function () {
        Route::livewire('users', 'pages::admin.users')->name('users.index');
        Route::livewire('systems', 'pages::admin.systems.index')->name('systems.index');
        Route::livewire('systems/{system:slug}', 'pages::admin.systems.show')->name('systems.show');
        Route::get('requests/export', function (RequestRecordExporter $exporter) {
            app(ActivityLogger::class)->log(
                ActivityAction::RecordsExported,
                metadata: ['requests' => PrivacyRequest::count()]
            );

            return response()->streamDownload(
                function () use ($exporter): void {
                    $output = fopen('php://output', 'w');

                    if ($output === false) {
                        throw new RuntimeException('Cannot open the output stream.');
                    }

                    $exporter->write($output);
                    fclose($output);
                },
                'privacy-requests-' . now(config('privacy.timezone'))->toDateString() . '.csv',
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            );
        })->name('requests.export');
    }
);

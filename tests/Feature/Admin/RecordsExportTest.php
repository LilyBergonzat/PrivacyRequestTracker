<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\ContactMethod;
use App\Enums\NotificationOutcome;
use App\Enums\RequestChannel;
use App\Enums\RequestStatus;
use App\Enums\RequestSystemStatus;
use App\Enums\RequestType;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\RequestIdentifier;
use App\Models\RequestSystem;
use App\Models\System;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['privacy.timezone' => 'Europe/Paris']);
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
});

/**
 * Downloads the export and returns its rows, header included.
 *
 * @return list<list<string>>
 */
function exportedRows(User $user): array
{
    $content = test()->actingAs($user)->get(route('admin.requests.export'))->assertOk()->streamedContent();

    expect($content)->toStartWith("\u{FEFF}");

    $lines = array_filter(explode("\n", substr($content, 3)), fn (string $line): bool => $line !== '');

    return array_values(array_map(fn (string $line): array => str_getcsv($line), $lines));
}

describe('access', function () {
    test('guests are redirected to the login page', function () {
        $this->get(route('admin.requests.export'))->assertRedirect(route('login'));
    });

    test('handlers cannot export the records', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.requests.export'))
            ->assertForbidden();

        expect(Activity::count())->toBe(0);
    });

    test('the export button is shown to admins only', function () {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('admin.requests.export'));
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSee(route('admin.requests.export'));
    });

    test('the export button is a plain download link', function () {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertSeeHtml('download')
            ->assertDontSeeHtml('href="' . route('admin.requests.export') . '" wire:navigate');
    });
});

describe('the file', function () {
    test('it is downloaded as a dated csv file', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.requests.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('privacy-requests-2026-03-01.csv');
    });

    test('an empty export only has the header row', function () {
        expect(exportedRows($this->admin))->toBe([[
            'Reference', 'Type', 'Channel', 'Status', 'Received on', 'Legal deadline',
            'Extended until', 'Closed on', 'Systems', 'Notification',
        ]]);
    });

    test('each request is one row with its facts, dates in the organization time zone', function () {
        $request = PrivacyRequest::factory()->create([
            'type' => RequestType::Deletion,
            'channel' => RequestChannel::Modmail,
            'received_at' => '2026-01-14 23:30:00',
            'due_at' => '2026-02-15 22:59:59',
        ]);
        $request->forceFill([
            'status' => RequestStatus::Completed,
            'extended_until' => '2026-03-31 21:59:59',
            'closed_at' => '2026-02-20 23:30:00',
            'notification_outcome' => NotificationOutcome::Sent,
        ])->save();

        RequestSystem::factory()->for($request)->for(System::factory()->create(['name' => 'Modmail bot']))
            ->resolved(RequestSystemStatus::Done)->create();
        RequestSystem::factory()->for($request)->for(System::factory()->create(['name' => 'Website']))
            ->resolved(RequestSystemStatus::NoDataFound)->create();

        expect(exportedRows($this->admin)[1])->toBe([
            $request->reference,
            'deletion',
            'modmail',
            'completed',
            '2026-01-15',
            '2026-02-15',
            '2026-03-31',
            '2026-02-21',
            'Modmail bot: Done; Website: No Data Found',
            'sent',
        ]);
    });

    test('open requests leave the closing columns empty', function () {
        PrivacyRequest::factory()->create();

        $row = exportedRows($this->admin)[1];

        expect($row[3])->toBe('received')
            ->and($row[6])->toBe('')
            ->and($row[7])->toBe('')
            ->and($row[9])->toBe('');
    });

    test('requests are listed oldest first', function () {
        $newer = PrivacyRequest::factory()->create(['received_at' => '2026-02-10 10:00:00']);
        $older = PrivacyRequest::factory()->create(['received_at' => '2026-01-05 10:00:00']);

        $rows = exportedRows($this->admin);

        expect($rows[1][0])->toBe($older->reference)
            ->and($rows[2][0])->toBe($newer->reference);
    });

    test('every request is exported, beyond a single batch', function () {
        PrivacyRequest::factory()->count(205)->create();

        expect(exportedRows($this->admin))->toHaveCount(206);
    });
});

describe('privacy and safety', function () {
    test('no identifier, contact or free text ever ends up in the file', function () {
        $request = PrivacyRequest::factory()->create([
            'contact_method' => ContactMethod::Email,
            'contact_details' => 'someone@example.com',
            'notes' => 'Secret note about them',
        ]);
        $request->forceFill([
            'identity_verification_notes' => 'Checked their passport',
            'extension_reason' => 'Complicated case',
            'rejection_reason' => 'Abusive',
            'notification_notes' => 'DMed them on Discord',
        ])->save();
        $identifier = RequestIdentifier::factory()->for($request)->create();
        RequestSystem::factory()->for($request)->resolved(RequestSystemStatus::Done)->create()
            ->forceFill(['result_summary' => 'Deleted 4 threads'])->save();

        $content = $this->actingAs($this->admin)->get(route('admin.requests.export'))->streamedContent();

        foreach (['someone@example.com', 'Secret note', 'passport', 'Complicated case', 'Abusive', 'DMed them', 'Deleted 4 threads', $identifier->hmac] as $secret) {
            expect($content)->not->toContain($secret);
        }
    });

    test('system names cannot inject spreadsheet formulas', function (string $name) {
        $request = PrivacyRequest::factory()->create();
        RequestSystem::factory()->for($request)->for(System::factory()->create(['name' => $name]))->create();

        expect(exportedRows($this->admin)[1][8])->toBe("'" . $name . ': Pending');
    })->with([
        '=HYPERLINK("https://evil.example")',
        '+1+1',
        '-2+3',
        '@SUM(A1)',
    ]);

    test('the export is logged with the number of requests only', function () {
        PrivacyRequest::factory()->count(3)->create();

        exportedRows($this->admin);

        $activity = Activity::sole();

        expect($activity->action)->toBe(ActivityAction::RecordsExported)
            ->and($activity->user_id)->toBe($this->admin->id)
            ->and($activity->privacy_request_id)->toBeNull()
            ->and($activity->metadata)->toBe(['requests' => 3]);
    });
});

<?php

declare(strict_types=1);

use App\Enums\DeadlineStatus;
use App\Services\DeadlineCalculator;
use Carbon\CarbonImmutable;

function parisCalculator(): DeadlineCalculator
{
    return new DeadlineCalculator('Europe/Paris', 7);
}

function utc(string $dateTime): CarbonImmutable
{
    return CarbonImmutable::parse($dateTime, 'UTC');
}

describe('legal deadline', function () {
    test('it is one calendar month later, at the end of the day in the organization time zone', function (string $receivedAt, string $expected) {
        $deadline = parisCalculator()->legalDeadline(utc($receivedAt));

        expect($deadline->toDateTimeString())->toBe($expected)
            ->and($deadline->timezoneName)->toBe('UTC');
    })->with([
        'mid month (winter, UTC+1)' => ['2026-01-15 10:00:00', '2026-02-15 22:59:59'],
        'january 31 does not overflow into march' => ['2026-01-31 10:00:00', '2026-02-28 22:59:59'],
        'january 31 in a leap year' => ['2028-01-31 10:00:00', '2028-02-29 22:59:59'],
        'march 31 (summer, UTC+2)' => ['2026-03-31 10:00:00', '2026-04-30 21:59:59'],
        'across daylight saving time' => ['2026-03-15 10:00:00', '2026-04-15 21:59:59'],
        'december 31 into the next year' => ['2026-12-31 10:00:00', '2027-01-31 22:59:59'],
    ]);

    test('the day that counts is the organization day, not the UTC day', function () {
        // 23:30 UTC on January 15 is already 00:30 on January 16 in Paris.
        $deadline = parisCalculator()->legalDeadline(utc('2026-01-15 23:30:00'));

        expect($deadline->toDateTimeString())->toBe('2026-02-16 22:59:59');
    });

    test('an organization in UTC ends its days at midnight UTC', function () {
        $deadline = (new DeadlineCalculator('UTC', 7))->legalDeadline(utc('2026-01-15 10:00:00'));

        expect($deadline->toDateTimeString())->toBe('2026-02-15 23:59:59');
    });

    test('an organization far ahead of UTC gets its own end of day', function () {
        $deadline = (new DeadlineCalculator('Asia/Tokyo', 7))->legalDeadline(utc('2026-01-15 10:00:00'));

        expect($deadline->toDateTimeString())->toBe('2026-02-15 14:59:59');
    });
});

describe('system deadline', function () {
    test('a system without its own response period uses the legal deadline', function () {
        $receivedAt = utc('2026-01-15 10:00:00');

        expect(parisCalculator()->systemDeadline($receivedAt, null))
            ->toEqual(parisCalculator()->legalDeadline($receivedAt));
    });

    test('a shorter response period gives an earlier deadline, at the end of the day', function () {
        $deadline = parisCalculator()->systemDeadline(utc('2026-01-15 10:00:00'), 14);

        expect($deadline->toDateTimeString())->toBe('2026-01-29 22:59:59')
            ->and($deadline->timezoneName)->toBe('UTC');
    });

    test('a response period never goes past the legal deadline', function (string $receivedAt, int $days) {
        $received = utc($receivedAt);

        expect(parisCalculator()->systemDeadline($received, $days))
            ->toEqual(parisCalculator()->legalDeadline($received));
    })->with([
        '31 days in january' => ['2026-01-15 10:00:00', 31],
        '31 days from january 31 (february is short)' => ['2026-01-31 10:00:00', 31],
        '45 days' => ['2026-01-15 10:00:00', 45],
    ]);

    test('a response period on the last possible day equals the legal deadline', function () {
        $deadline = parisCalculator()->systemDeadline(utc('2026-01-31 10:00:00'), 28);

        expect($deadline->toDateTimeString())->toBe('2026-02-28 22:59:59');
    });
});

describe('extensions', function () {
    test('the maximum extension is three calendar months later', function (string $receivedAt, string $expected) {
        expect(parisCalculator()->maxExtension(utc($receivedAt))->toDateTimeString())->toBe($expected);
    })->with([
        'mid month' => ['2026-01-15 10:00:00', '2026-04-15 21:59:59'],
        'november 30 into february' => ['2026-11-30 10:00:00', '2027-02-28 22:59:59'],
        'november 30 into a leap february' => ['2027-11-30 10:00:00', '2028-02-29 22:59:59'],
    ]);

    test('telling the requester is on time until the end of the first month', function (string $now, bool $late) {
        $receivedAt = utc('2026-01-15 10:00:00');

        expect(parisCalculator()->isExtensionNoticeLate($receivedAt, utc($now)))->toBe($late);
    })->with([
        'same day' => ['2026-01-15 12:00:00', false],
        'last second of the first month' => ['2026-02-15 22:59:59', false],
        'first second after the first month' => ['2026-02-15 23:00:00', true],
        'weeks later' => ['2026-03-01 10:00:00', true],
    ]);
});

test('the received date is never modified by a calculation', function () {
    $receivedAt = utc('2026-01-31 10:00:00');
    $calculator = parisCalculator();

    $calculator->legalDeadline($receivedAt);
    $calculator->systemDeadline($receivedAt, 14);
    $calculator->maxExtension($receivedAt);
    $calculator->isExtensionNoticeLate($receivedAt, utc('2026-02-01 00:00:00'));

    expect($receivedAt->toDateTimeString())->toBe('2026-01-31 10:00:00')
        ->and($receivedAt->timezoneName)->toBe('UTC');
});

describe('deadline status', function () {
    test('the status depends on how close the deadline is', function (string $now, DeadlineStatus $expected) {
        $deadline = utc('2026-02-15 22:59:59');

        expect(parisCalculator()->status($deadline, utc($now)))->toBe($expected);
    })->with([
        'weeks ahead' => ['2026-01-20 10:00:00', DeadlineStatus::OnTrack],
        'eight days ahead' => ['2026-02-07 22:00:00', DeadlineStatus::OnTrack],
        'exactly seven days ahead' => ['2026-02-08 22:59:59', DeadlineStatus::Soon],
        'three days ahead' => ['2026-02-12 10:00:00', DeadlineStatus::Soon],
        'last second' => ['2026-02-15 22:59:59', DeadlineStatus::Soon],
        'one second late' => ['2026-02-15 23:00:00', DeadlineStatus::Overdue],
        'weeks late' => ['2026-03-10 10:00:00', DeadlineStatus::Overdue],
    ]);

    test('the warning window follows the configured number of days', function () {
        $calculator = new DeadlineCalculator('UTC', 2);
        $deadline = utc('2026-02-15 23:59:59');

        expect($calculator->status($deadline, utc('2026-02-12 10:00:00')))->toBe(DeadlineStatus::OnTrack)
            ->and($calculator->status($deadline, utc('2026-02-14 10:00:00')))->toBe(DeadlineStatus::Soon);
    });

    test('every status has a badge color', function (DeadlineStatus $status) {
        expect($status->colour())->toBeIn(['red', 'amber', 'zinc']);
    })->with(DeadlineStatus::cases());
});

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PrivacyRequestPurger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('privacy:purge {--dry-run}')]
#[Description('Purge expired requests')]
class PurgeExpiredRequests extends Command
{
    /**
     * @throws Throwable
     */
    public function handle(PrivacyRequestPurger $requestPurger): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $requestsPurged = $requestPurger->countExpired(CarbonImmutable::now(config('privacy.timezone')));
            $leftoverContactsPurged = $requestPurger->countLeftoverContactDetails();
            $summaryRequests = trans_choice('Would have purged :count request.|Would have purged :count requests.', $requestsPurged);
            $summaryContacts = trans_choice('Would have cleared leftover contact details on :count request.|Would have cleared leftover contact details on :count requests.', $leftoverContactsPurged);

            $this->warn(__('Command ran in dry mode. No entries were deleted.'));
            $this->info($summaryRequests);
            $this->info($summaryContacts);

            return self::SUCCESS;
        }

        $requestsPurged = $requestPurger->purgeExpired(CarbonImmutable::now(config('privacy.timezone')));
        $leftoverContactsPurged = $requestPurger->purgeLeftoverContactDetails();
        $summaryRequests = trans_choice('Purged :count request.|Purged :count requests.', $requestsPurged);
        $summaryContacts = trans_choice('Cleared leftover contact details on :count request.|Cleared leftover contact details on :count requests.', $leftoverContactsPurged);

        $this->info($summaryRequests);
        $this->info($summaryContacts);

        return self::SUCCESS;
    }
}

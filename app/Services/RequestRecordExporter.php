<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

readonly class RequestRecordExporter
{
    /**
     * @param  resource  $output
     */
    public function write($output): void
    {
        fwrite($output, "\u{FEFF}");
        fputcsv($output, [
            'Reference',
            'Type',
            'Channel',
            'Status',
            'Received on',
            'Legal deadline',
            'Extended until',
            'Closed on',
            'Systems',
            'Notification',
        ]);

        PrivacyRequest::with('requestSystems.system')
            ->oldest('received_at')
            ->chunk(200, function (Collection $requests) use ($output): void {
                foreach ($requests as $request) {
                    fputcsv($output, $this->row($request));
                }
            });
    }

    /**
     * @return list<string>
     */
    private function row(PrivacyRequest $request): array
    {
        return [
            $request->reference,
            $request->type->value,
            $request->channel->value,
            $request->status->value,
            $this->localDateTime($request->received_at),
            $this->localDateTime($request->due_at),
            $this->localDateTime($request->extended_until),
            $this->localDateTime($request->closed_at),
            $request->requestSystems->map(
                fn (RequestSystem $row): string => $this->safeCell($row->system->name) . ': ' . $row->status->label()
            )->implode('; '),
            $request->notification_outcome?->value,
        ];
    }

    private function safeCell(string $value): string
    {
        return preg_replace('#^([=+@-])#', '\'$1', $value);
    }

    private function localDateTime(?CarbonImmutable $datetime): ?string
    {
        return $datetime?->timezone(config('privacy.timezone'))->isoFormat('YYYY-MM-DD');
    }
}

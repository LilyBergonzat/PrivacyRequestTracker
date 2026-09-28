<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PrivacyRequest;
use App\ValueObjects\SubjectIdentifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class MatchingRequestFinder
{
    public function __construct(private readonly SubjectHasher $subjectHasher) {}

    /**
     * @param  list<SubjectIdentifier>  $identifiers
     * @return Collection<int, PrivacyRequest>
     */
    public function find(array $identifiers): Collection
    {
        $collection = new Collection;

        if (empty($identifiers)) {
            return $collection;
        }

        $candidates = array_reduce(
            $identifiers,
            fn (array $carry, SubjectIdentifier $identifier): array => array_merge(
                $carry,
                $this->subjectHasher->candidates($identifier)
            ),
            []
        );

        return PrivacyRequest::whereHas('identifiers', function (Builder $query) use ($candidates): void {
            $query->where(function (Builder $query) use ($candidates): void {
                foreach ($candidates as $pair) {
                    $query->orWhere(fn (Builder $q): Builder => $q
                        ->where('key_version', $pair->version)
                        ->where('hmac', $pair->hmac));
                }
            });
        })->latest('received_at')->get();
    }
}

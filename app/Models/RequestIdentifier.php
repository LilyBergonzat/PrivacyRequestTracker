<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RequestIdentifierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $privacy_request_id
 * @property string $identifier_type
 * @property string $hmac
 * @property string $key_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['identifier_type', 'hmac', 'key_version'])]
class RequestIdentifier extends Model
{
    /** @use HasFactory<RequestIdentifierFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<PrivacyRequest, $this>
     */
    public function privacyRequest(): BelongsTo
    {
        return $this->belongsTo(PrivacyRequest::class);
    }
}

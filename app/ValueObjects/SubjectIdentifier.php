<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\IdentifierType;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class SubjectIdentifier
{
    public string $value;

    public ?string $label;

    public function __construct(
        public IdentifierType $type,
        #[SensitiveParameter] string $value,
        ?string $label = null,
    ) {
        $slug = $label !== null ? Str::slug($label, '_') : '';
        $formattedLabel = $slug !== '' ? $slug : null;

        if ($this->type === IdentifierType::Other && $formattedLabel === null) {
            throw new InvalidArgumentException('An identifier of type "other" should always specify a label');
        }

        if ($this->type !== IdentifierType::Other && $formattedLabel !== null) {
            throw new InvalidArgumentException('An identifier that is not of type "other" should not specify a label');
        }

        $this->value = $this->type->normalize($value);
        $this->label = $formattedLabel;
    }

    public function prefix(): string
    {
        return $this->type->value . ($this->type === IdentifierType::Other ? ':' . $this->label : '');
    }
}

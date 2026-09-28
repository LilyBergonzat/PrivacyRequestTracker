<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RequestType;
use App\Models\System;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;
use Throwable;

class SystemTemplateImporter
{
    /**
     * @throws FileNotFoundException
     * @throws JsonException
     * @throws Throwable
     */
    public function importFile(string $path): ?System
    {
        return $this->import(json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $template
     *
     * @throws Throwable
     */
    public function import(array $template): ?System
    {
        $validated = Validator::make($template, [
            'format_version' => ['required', 'integer', 'in:1'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'response_period_days' => ['nullable', 'integer', 'min:1', 'max:31'],
            'steps' => ['present', 'array'],
            'steps.*.title' => ['required', 'string', 'max:255'],
            'steps.*.request_type' => ['nullable', Rule::enum(RequestType::class)],
            'steps.*.instructions' => ['required', 'array', 'min:1'],
            'steps.*.instructions.*' => ['present', 'string'],
        ])->validate();

        if (System::where('slug', $template['slug'])->exists()) {
            return null;
        }

        return DB::transaction(function () use ($validated): System {
            $system = new System([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'response_period_days' => $validated['response_period_days'] ?? null,
            ]);

            $system->slug = $validated['slug'];
            $system->save();

            foreach ($validated['steps'] as $step) {
                $system->steps()->create([
                    'title' => $step['title'],
                    'request_type' => $step['request_type'] ?? null,
                    'instructions' => implode("\n", $step['instructions']),
                ]);
            }

            return $system;
        });
    }
}

<?php

declare(strict_types=1);

use App\Enums\RequestType;
use App\Models\System;
use App\Models\SystemStep;
use App\Services\SystemTemplateImporter;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function systemTemplate(array $overrides = []): array
{
    return [
        'format_version' => 1,
        'slug' => 'modmail-bot',
        'name' => 'Modmail bot',
        'description' => 'Stored in **MongoDB**.',
        'response_period_days' => null,
        'steps' => [
            ['title' => 'Connect', 'request_type' => null, 'instructions' => ['mongosh', '', 'use modmail_bot']],
            ['title' => 'Delete threads', 'request_type' => 'deletion', 'instructions' => ['db.logs.deleteMany()']],
        ],
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->importer = app(SystemTemplateImporter::class);
});

describe('importing', function () {
    test('a template creates an active system with its steps', function () {
        $system = $this->importer->import(systemTemplate());

        expect($system)->toBeInstanceOf(System::class)
            ->and($system->slug)->toBe('modmail-bot')
            ->and($system->name)->toBe('Modmail bot')
            ->and($system->description)->toBe('Stored in **MongoDB**.')
            ->and($system->response_period_days)->toBeNull()
            ->and($system->refresh()->is_active)->toBeTrue()
            ->and($system->steps()->count())->toBe(2);
    });

    test('steps keep the order and the request types of the template', function () {
        $system = $this->importer->import(systemTemplate());

        $steps = $system->steps()->ordered()->get();

        expect($steps->pluck('title')->all())->toBe(['Connect', 'Delete threads'])
            ->and($steps->pluck('sort_order')->all())->toBe([0, 1])
            ->and($steps[0]->request_type)->toBeNull()
            ->and($steps[1]->request_type)->toBe(RequestType::Deletion);
    });

    test('instruction lines are joined with line breaks, empty lines included', function () {
        $system = $this->importer->import(systemTemplate());

        expect($system->steps()->ordered()->first()->instructions)->toBe("mongosh\n\nuse modmail_bot");
    });

    test('the slug of the template is used even if the name would give another one', function () {
        $system = $this->importer->import(systemTemplate(['slug' => 'modmail', 'name' => 'Our Support Bot']));

        expect($system->slug)->toBe('modmail');
    });

    test('the custom response period is imported', function () {
        $system = $this->importer->import(systemTemplate(['response_period_days' => 14]));

        expect($system->response_period_days)->toBe(14);
    });

    test('an imported system goes at the end of the list', function () {
        System::factory()->create(['sort_order' => 3]);

        $system = $this->importer->import(systemTemplate());

        expect($system->sort_order)->toBe(4);
    });

    test('optional keys can be left out', function () {
        $template = systemTemplate([
            'steps' => [['title' => 'Connect', 'instructions' => ['mongosh']]],
        ]);
        unset($template['description'], $template['response_period_days']);

        $system = $this->importer->import($template);

        expect($system->description)->toBeNull()
            ->and($system->response_period_days)->toBeNull()
            ->and($system->steps()->first()->request_type)->toBeNull();
    });

    test('a template without steps is allowed', function () {
        $system = $this->importer->import(systemTemplate(['steps' => []]));

        expect($system->steps()->count())->toBe(0);
    });
});

describe('existing systems', function () {
    test('a template whose slug already exists is skipped', function () {
        $existing = System::factory()->create(['slug' => 'modmail-bot', 'name' => 'Customized']);

        expect($this->importer->import(systemTemplate()))->toBeNull()
            ->and(System::count())->toBe(1)
            ->and($existing->refresh()->name)->toBe('Customized')
            ->and(SystemStep::count())->toBe(0);
    });

    test('importing twice creates the system only once', function () {
        $this->importer->import(systemTemplate());
        $this->importer->import(systemTemplate());

        expect(System::count())->toBe(1)
            ->and(SystemStep::count())->toBe(2);
    });
});

describe('validation', function () {
    test('an invalid template is rejected and nothing is created', function (array $overrides, string $field) {
        try {
            $this->importer->import(systemTemplate($overrides));
            $this->fail('The invalid template was accepted.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
        }

        expect(System::count())->toBe(0)
            ->and(SystemStep::count())->toBe(0);
    })->with([
        'missing format version' => [['format_version' => null], 'format_version'],
        'unknown format version' => [['format_version' => 2], 'format_version'],
        'missing slug' => [['slug' => ''], 'slug'],
        'slug with uppercase' => [['slug' => 'Modmail-Bot'], 'slug'],
        'slug with spaces' => [['slug' => 'modmail bot'], 'slug'],
        'slug with a trailing dash' => [['slug' => 'modmail-'], 'slug'],
        'missing name' => [['name' => ''], 'name'],
        'name too long' => [['name' => str_repeat('a', 256)], 'name'],
        'zero days' => [['response_period_days' => 0], 'response_period_days'],
        'more than a month' => [['response_period_days' => 32], 'response_period_days'],
        'steps not a list' => [['steps' => 'nope'], 'steps'],
        'step without title' => [['steps' => [['title' => '', 'instructions' => ['x']]]], 'steps.0.title'],
        'step with unknown request type' => [['steps' => [['title' => 'X', 'request_type' => 'bogus', 'instructions' => ['x']]]], 'steps.0.request_type'],
        'step without instructions' => [['steps' => [['title' => 'X', 'instructions' => []]]], 'steps.0.instructions'],
        'instructions as a string' => [['steps' => [['title' => 'X', 'instructions' => 'x']]], 'steps.0.instructions'],
        'instruction line not a string' => [['steps' => [['title' => 'X', 'instructions' => [['x']]]]], 'steps.0.instructions.0'],
    ]);

    test('a template without steps key is rejected', function () {
        $template = systemTemplate();
        unset($template['steps']);

        $this->importer->import($template);
    })->throws(ValidationException::class);

    test('a bad step does not leave a half imported system behind', function () {
        $template = systemTemplate([
            'steps' => [
                ['title' => 'Fine', 'instructions' => ['x']],
                ['title' => str_repeat('a', 256), 'instructions' => ['x']],
            ],
        ]);

        expect(fn () => $this->importer->import($template))->toThrow(ValidationException::class)
            ->and(System::count())->toBe(0)
            ->and(SystemStep::count())->toBe(0);
    });
});

describe('files', function () {
    beforeEach(function () {
        $this->path = tempnam(sys_get_temp_dir(), 'template') . '.json';
    });

    afterEach(function () {
        File::delete($this->path);
    });

    test('a template file is imported', function () {
        File::put($this->path, json_encode(systemTemplate(), JSON_THROW_ON_ERROR));

        expect($this->importer->importFile($this->path)?->slug)->toBe('modmail-bot');
    });

    test('a broken json file throws', function () {
        File::put($this->path, '{ "slug": "modmail-bot", ');

        $this->importer->importFile($this->path);
    })->throws(JsonException::class);

    test('a missing file throws', function () {
        $this->importer->importFile($this->path . '.missing');
    })->throws(FileNotFoundException::class);
});

describe('shipped templates', function () {
    test('every shipped template is valid', function (string $path) {
        expect($this->importer->importFile($path))->toBeInstanceOf(System::class);
    })->with(fn (): array => glob(dirname(__DIR__, 3) . '/resources/system-templates/*.json') ?: []);

    test('the modmail template has its eight steps with the agreed request types', function () {
        $system = $this->importer->importFile(resource_path('system-templates/modmail-bot.json'));

        $types = $system->steps()->ordered()->get()->map(fn (SystemStep $step): ?string => $step->request_type?->value)->all();

        expect($system->response_period_days)->toBeNull()
            ->and($types)->toBe([null, null, 'deletion', 'deletion', 'deletion', 'deletion', null, 'deletion']);
    });

    test('every modmail command is self-contained, with the discord id filled in place', function () {
        $system = $this->importer->importFile(resource_path('system-templates/modmail-bot.json'));

        $instructions = $system->steps()->ordered()->pluck('instructions', 'title');

        foreach (['Preview what exists', 'Delete their own threads', 'Remove their messages from other threads', 'Delete persistent staff notes about them', 'Block list: kept by default'] as $title) {
            expect($instructions[$title])->toContain('"{{discord_id}}"');
        }

        expect($instructions->implode("\n"))->not->toMatch('/\buid\b/');
    });
});

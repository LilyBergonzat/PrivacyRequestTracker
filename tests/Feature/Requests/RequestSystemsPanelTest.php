<?php

declare(strict_types=1);

use App\Enums\RequestStatus;
use App\Enums\RequestSystemStatus;
use App\Enums\RequestType;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\StepCompletion;
use App\Models\System;
use App\Models\SystemStep;
use App\Models\User;
use App\Services\PrivacyRequestWorkflow;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->handler = User::factory()->create(['name' => 'Lily Handler']);

    $this->request = PrivacyRequest::factory()->create(['type' => RequestType::Deletion]);
    $this->request->forceFill(['status' => RequestStatus::InProgress])->save();

    $this->modmail = System::factory()->create(['name' => 'Modmail Bot', 'sort_order' => 1]);
    $this->website = System::factory()->create(['name' => 'Website', 'sort_order' => 0]);

    $this->connect = SystemStep::factory()->for($this->modmail)->create(['title' => 'Connect to the database']);
    $this->deleteThreads = SystemStep::factory()->for($this->modmail)->forType(RequestType::Deletion)->create(['title' => 'Delete their threads']);
    SystemStep::factory()->for($this->modmail)->forType(RequestType::Access)->create(['title' => 'Export their threads']);
    SystemStep::factory()->for($this->modmail)->inactive()->create(['title' => 'Old retired step']);

    $this->modmailRow = RequestSystem::factory()->for($this->request)->for($this->modmail)->create();
    $this->websiteRow = RequestSystem::factory()->for($this->request)->for($this->website)->create();
});

function systemsPanel(): Testable
{
    return Livewire::actingAs(test()->handler)
        ->test('pages::requests.show', ['privacyRequest' => test()->request->fresh()]);
}

describe('display', function () {
    test('systems are shown in their configured order', function () {
        systemsPanel()->assertSeeInOrder(['Website', 'Modmail Bot']);
    });

    test('only the active steps that apply to this type of request are shown', function () {
        systemsPanel()
            ->assertSee('Connect to the database')
            ->assertSee('Delete their threads')
            ->assertDontSee('Export their threads')
            ->assertDontSee('Old retired step')
            ->assertSee('0 of 2 steps');
    });

    test('a system without applicable steps says so', function () {
        systemsPanel()->assertSee('No steps for this type of request.');
    });

    test('the systems are read-only until the identity is verified', function () {
        $this->request->forceFill(['status' => RequestStatus::VerifyingIdentity])->save();

        systemsPanel()
            ->assertSee('Verify the identity before working on the systems.')
            ->assertDontSee('Resolve this system');
    });

    test('a completed step that was later deleted still shows with its title', function () {
        app(PrivacyRequestWorkflow::class)->completeStep($this->modmailRow, $this->connect, $this->handler);
        $this->connect->delete();

        systemsPanel()->assertSee('Connect to the database (step removed)');
    });
});

describe('steps', function () {
    test('a step can be checked and unchecked from the page', function () {
        $page = systemsPanel()
            ->call('toggleStep', $this->modmailRow->id, $this->connect->id)
            ->assertSee('1 of 2 steps')
            ->assertSee('Done by Lily Handler');

        expect(StepCompletion::sole()->completed_by)->toBe($this->handler->id);

        $page->call('toggleStep', $this->modmailRow->id, $this->connect->id)
            ->assertSee('0 of 2 steps');

        expect(StepCompletion::count())->toBe(0);
    });

    test('a system row of another request cannot be used from this page', function () {
        $foreignRow = RequestSystem::factory()->for($this->modmail)->create();

        systemsPanel()
            ->call('toggleStep', $foreignRow->id, $this->connect->id)
            ->assertNotFound();

        expect(StepCompletion::count())->toBe(0);
    });

    test('a step of another system cannot be checked on this row', function () {
        $foreignStep = SystemStep::factory()->for($this->website)->create();

        systemsPanel()
            ->call('toggleStep', $this->modmailRow->id, $foreignStep->id)
            ->assertNotFound();

        expect(StepCompletion::count())->toBe(0);
    });

    test('checking a step before the identity is verified shows a message instead of failing', function () {
        $this->request->forceFill(['status' => RequestStatus::VerifyingIdentity])->save();

        systemsPanel()
            ->call('toggleStep', $this->modmailRow->id, $this->connect->id)
            ->assertOk()
            ->assertDispatched('toast-show');

        expect(StepCompletion::count())->toBe(0);
    });
});

describe('resolving', function () {
    test('a system can be resolved with an outcome and a summary', function () {
        systemsPanel()
            ->set("resolutions.{$this->websiteRow->id}.status", RequestSystemStatus::NoDataFound->value)
            ->set("resolutions.{$this->websiteRow->id}.summary", 'No account with this email')
            ->call('resolveSystem', $this->websiteRow->id)
            ->assertHasNoErrors()
            ->assertDispatched('toast-show')
            ->assertSee('No account with this email')
            ->assertSee('Resolved by Lily Handler');

        $this->websiteRow->refresh();

        expect($this->websiteRow->status)->toBe(RequestSystemStatus::NoDataFound)
            ->and($this->websiteRow->result_summary)->toBe('No account with this email');
    });

    test('invalid resolutions are rejected', function (array $input, string $field) {
        $key = "resolutions.{$this->websiteRow->id}";

        systemsPanel()
            ->set("{$key}.status", $input['status'])
            ->set("{$key}.summary", $input['summary'])
            ->call('resolveSystem', $this->websiteRow->id)
            ->assertHasErrors("{$key}.{$field}");

        expect($this->websiteRow->refresh()->status)->toBe(RequestSystemStatus::Pending);
    })->with([
        'missing outcome' => [['status' => '', 'summary' => 'Done'], 'status'],
        'pending is not an outcome' => [['status' => 'pending', 'summary' => 'Done'], 'status'],
        'unknown outcome' => [['status' => 'maybe', 'summary' => 'Done'], 'status'],
        'missing summary' => [['status' => 'done', 'summary' => ''], 'summary'],
        'summary too long' => [['status' => 'done', 'summary' => str_repeat('a', 5001)], 'summary'],
    ]);

    test('error messages use readable field names', function () {
        $errors = systemsPanel()
            ->call('resolveSystem', $this->websiteRow->id)
            ->errors();

        expect($errors->first("resolutions.{$this->websiteRow->id}.status"))->toBe('The outcome field is required.')
            ->and($errors->first("resolutions.{$this->websiteRow->id}.summary"))->toBe('The summary field is required.');
    });

    test('resolving asks for confirmation only while steps are unchecked', function () {
        systemsPanel()
            ->assertSeeHtml('Only 0 of 2 steps are checked. Resolve anyway?')
            ->call('toggleStep', $this->modmailRow->id, $this->connect->id)
            ->call('toggleStep', $this->modmailRow->id, $this->deleteThreads->id)
            ->assertDontSeeHtml('steps are checked. Resolve anyway?');
    });

    test('resolving the last system updates the request status in the header', function () {
        app(PrivacyRequestWorkflow::class)->resolveSystem($this->modmailRow, RequestSystemStatus::Done, 'Done', $this->handler);

        systemsPanel()
            ->assertSee('In Progress')
            ->set("resolutions.{$this->websiteRow->id}.status", RequestSystemStatus::NotApplicable->value)
            ->set("resolutions.{$this->websiteRow->id}.summary", 'They never had an account')
            ->call('resolveSystem', $this->websiteRow->id)
            ->assertSee('Awaiting Notification');
    });

    test('resolving a system someone else already resolved shows a message', function () {
        $page = systemsPanel()
            ->set("resolutions.{$this->websiteRow->id}.status", RequestSystemStatus::Done->value)
            ->set("resolutions.{$this->websiteRow->id}.summary", 'Mine');

        app(PrivacyRequestWorkflow::class)->resolveSystem($this->websiteRow, RequestSystemStatus::NoDataFound, 'Theirs', $this->handler);

        $page->call('resolveSystem', $this->websiteRow->id)
            ->assertOk()
            ->assertDispatched('toast-show');

        expect($this->websiteRow->refresh()->result_summary)->toBe('Theirs');
    });
});

describe('reopening', function () {
    beforeEach(function () {
        $workflow = app(PrivacyRequestWorkflow::class);
        $workflow->resolveSystem($this->modmailRow, RequestSystemStatus::Done, 'Deleted 4 threads', $this->handler);
        $workflow->resolveSystem($this->websiteRow, RequestSystemStatus::NoDataFound, 'Nothing', $this->handler);
    });

    test('a resolved system can be reopened, which sends the request back in progress', function () {
        systemsPanel()
            ->assertSee('Awaiting Notification')
            ->call('reopenSystem', $this->modmailRow->id)
            ->assertDispatched('toast-show')
            ->assertSee('In Progress')
            ->assertSee('Resolve this system');

        expect($this->modmailRow->refresh()->status)->toBe(RequestSystemStatus::Pending);
    });

    test('the summary of a reopened system is kept in the form', function () {
        app(PrivacyRequestWorkflow::class)->reopenSystem($this->modmailRow->fresh());

        systemsPanel()->assertSet("resolutions.{$this->modmailRow->id}.summary", 'Deleted 4 threads');
    });

    test('a system of another request cannot be reopened from this page', function () {
        $foreignRow = RequestSystem::factory()->resolved(RequestSystemStatus::Done)->create();

        systemsPanel()
            ->call('reopenSystem', $foreignRow->id)
            ->assertNotFound();

        expect($foreignRow->refresh()->status)->toBe(RequestSystemStatus::Done);
    });

    test('systems of a closed request cannot be reopened', function () {
        $this->request->forceFill(['status' => RequestStatus::Completed])->save();

        systemsPanel()
            ->assertDontSee('Reopen')
            ->call('reopenSystem', $this->modmailRow->id)
            ->assertDispatched('toast-show');

        expect($this->modmailRow->refresh()->status)->toBe(RequestSystemStatus::Done);
    });
});

<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

beforeEach(function () {
    $this->hotFile = public_path('hot');
    $this->hadHotFile = File::exists($this->hotFile);
    $this->hotFileContents = $this->hadHotFile ? File::get($this->hotFile) : null;
    File::delete($this->hotFile);
});

afterEach(function () {
    if ($this->hadHotFile) {
        File::put($this->hotFile, $this->hotFileContents);
    }
});

test('every page sends the basic security headers', function () {
    $this->get(route('login'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
});

test('hsts is only sent over https', function () {
    $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');

    $this->get(str_replace('http://', 'https://', route('login')))
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('a strict content security policy is sent with a fresh nonce', function () {
    $first = $this->get(route('login'))->headers->get('Content-Security-Policy');
    $second = $this->get(route('login'))->headers->get('Content-Security-Policy');

    preg_match("/'nonce-([^']+)'/", $first, $firstNonce);
    preg_match("/'nonce-([^']+)'/", $second, $secondNonce);

    expect($first)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain("'unsafe-eval'")
        ->and($firstNonce[1] ?? null)->not->toBeNull()
        ->and($firstNonce[1])->not->toBe($secondNonce[1] ?? null);
});

test('scripts are not allowed to run inline without the nonce', function () {
    $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');

    preg_match('/script-src ([^;]+)/', $csp, $scriptSrc);

    expect($scriptSrc[1])->not->toContain("'unsafe-inline'")
        ->toContain("'nonce-");
});

test('every inline script and style on a page carries the nonce', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));
    preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $nonce);

    preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>/', $response->getContent(), $inlineScripts);

    expect($inlineScripts[0])->not->toBeEmpty();

    foreach ($inlineScripts[0] as $tag) {
        expect($tag)->toContain('nonce="' . $nonce[1] . '"');
    }
});

test('the content security policy is skipped while the vite dev server runs', function () {
    File::put($this->hotFile, 'http://localhost:5173');

    expect(Vite::isRunningHot())->toBeTrue();

    $this->get(route('login'))
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('X-Frame-Options', 'DENY');

    File::delete($this->hotFile);
});

test('livewire uses its csp safe build', function () {
    expect(config('livewire.csp_safe'))->toBeTrue();
});

test('no template relies on inline alpine logic that the csp build cannot parse', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.blade.php'))
        ->filter(fn ($file): bool => preg_match('/x-data="\s*\{|(?:@click|x-on:click)="[^"]*(?:=|\(\))|(?:x-[a-z:.-]+|@[a-z.-]+)="[^"]*=>/', $file->getContents()) === 1)
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

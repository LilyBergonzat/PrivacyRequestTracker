<?php

declare(strict_types=1);

test('the homepage redirects to the dashboard', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect('/dashboard');
});

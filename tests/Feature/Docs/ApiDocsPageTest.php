<?php

use App\Models\User;

it('renders the API reference docs page with the embedded spec viewer', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('docs.api'));

    $response->assertStatus(200)
        ->assertSee('Interactive reference')
        ->assertSee('/api/v1/openapi.json', escape: false)
        ->assertSee('redoc.standalone.js', escape: false);
});

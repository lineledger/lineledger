<?php

test('guests are redirected to login from home', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});

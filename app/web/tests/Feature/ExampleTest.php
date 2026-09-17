<?php

it('redirects guests away from the Laravel welcome screen', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login', absolute: false));
});

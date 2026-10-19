<?php

// Tahap 6 — header keamanan pada respons API

it('respons API membawa header keamanan, termasuk saat error 401', function () {
    foreach (['/api/v1/me', '/up'] as $path) {
        $this->getJson($path)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
            ->assertHeader('Permissions-Policy');
    }
});

it('HSTS hanya dikirim di produksi lewat HTTPS', function () {
    $this->getJson('/api/v1/me')->assertHeaderMissing('Strict-Transport-Security');

    $this->app['env'] = 'production';
    $this->getJson('https://localhost/api/v1/me')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

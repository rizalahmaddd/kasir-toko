<?php

it('sends a content security policy without inline scripts', function () {
    $csp = $this->get(route('login'))->assertOk()->headers->get('Content-Security-Policy');

    expect($csp)->toContain("script-src 'self' 'unsafe-eval'")
        ->not->toContain("'unsafe-inline' https://challenges")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'self'");
});

it('renders pages without inline script blocks or inline event handlers', function () {
    actingAsAdmin();

    foreach ([route('login'), route('dashboard'), route('sales.index')] as $url) {
        $html = $this->get($url)->getContent();

        expect($html)->not->toMatch('/<script(?![^>]*\bsrc=)[^>]*>\s*\S/i')
            ->not->toMatch('/\son(click|load|error|submit|change)=/i');
    }
});

it('can switch the policy to report-only', function () {
    config(['app.csp.report_only' => true]);

    $response = $this->get(route('login'));

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'");
});

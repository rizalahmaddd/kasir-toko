<?php

use App\Support\Features;
use App\Support\OpenApi\OpenApiGenerator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('guards every business endpoint with a known feature toggle', function () {
    $ungated = [];

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! Str::startsWith($name, 'api.v1.') || Str::is(['api.v1.auth.*', 'api.v1.lookups.*', 'api.v1.dashboard*', 'api.v1.notifications.*'], $name)) {
            continue;
        }

        $features = collect($route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'feature:'))
            ->flatMap(fn (string $middleware) => explode(',', Str::after($middleware, 'feature:')));

        if ($features->isEmpty()) {
            $ungated[] = $name;
        }

        $features->each(fn (string $feature) => expect(Features::isKnown($feature))->toBeTrue("Unknown feature [{$feature}] on {$name}"));
    }

    expect($ungated)->toBe([]);
});

it('documents every API endpoint', function () {
    $document = app(OpenApiGenerator::class)->generate();

    $documented = collect($document['paths'])->flatMap(fn (array $operations, string $path) => collect(array_keys($operations))->map(fn ($method) => strtoupper($method).' '.$path));

    $routes = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(fn ($route) => collect(array_diff($route->methods(), ['HEAD', 'PATCH']))
            ->map(fn ($method) => $method.' /'.preg_replace('/\{(\w+)(?::\w+)?\??\}/', '{$1}', $route->uri())));

    expect($routes->diff($documented)->values()->all())->toBe([])
        ->and($document['components']['schemas'])->toHaveKeys(['Token', 'Customer', 'CurrentUser']);
});

it('serves the OpenAPI document for Scalar', function () {
    $this->getJson('/api/openapi.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('paths./api/v1/auth/login.post.security', []);

    $this->get('/docs/api')->assertOk();
});

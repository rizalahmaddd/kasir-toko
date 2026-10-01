<?php

namespace App\Support\OpenApi;

use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Builds the OpenAPI 3.1 document rendered by Scalar straight from the registered API routes:
 * summaries from controller PHPDoc, request bodies from FormRequest rules, and responses from
 * the array shapes documented on API resources.
 */
class OpenApiGenerator
{
    public const PREFIX = 'api/v1';

    private ResourceSchemas $resources;

    /** @var array<string, array{name: string, group: string, description: ?string}> */
    private array $tags = [];

    public function __construct(private Router $router) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $this->resources = new ResourceSchemas;
        $this->tags = [];
        $paths = [];

        foreach ($this->router->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), self::PREFIX.'/') || ! is_string($route->getAction('controller'))) {
                continue;
            }

            [$class, $method] = Str::parseCallback($route->getAction('controller'), '__invoke');

            if (! class_exists($class) || ! method_exists($class, $method) || $route->getAction('openapi') === false) {
                continue;
            }

            $path = '/'.preg_replace('/\{(\w+)(?::\w+)?\??\}/', '{$1}', $route->uri());

            // PUT|PATCH routes are documented once, as PUT.
            foreach (array_diff($route->methods(), ['HEAD', ...(in_array('PUT', $route->methods(), true) ? ['PATCH'] : [])]) as $httpMethod) {
                $paths[$path][strtolower($httpMethod)] = $this->operation($route, new ReflectionMethod($class, $method));
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => config('app.name').' Mobile API',
                'version' => '1.0.0',
                'description' => $this->introduction(),
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/'), 'description' => config('app.env')]],
            'security' => [['bearerAuth' => []]],
            'tags' => array_values(array_map(fn (array $tag) => array_filter([
                'name' => $tag['name'],
                'description' => $tag['description'],
            ]), $this->tags)),
            'x-tagGroups' => $this->tagGroups(),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Token dari `POST /api/v1/auth/login` atau `POST /api/v1/auth/otp/verify`.',
                    ],
                ],
                'schemas' => [...$this->resources->all(), ...$this->standardSchemas()],
                'responses' => $this->standardResponses(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, ReflectionMethod $method): array
    {
        $tag = $this->tagFor($method->getDeclaringClass());
        [$summary, $description] = $this->docSummary($method);
        $isPublic = ! in_array('auth:sanctum', $route->gatherMiddleware(), true);

        $operation = array_filter([
            'tags' => [$tag],
            'summary' => $summary,
            'description' => $description,
            'operationId' => $route->getName() ? Str::camel(str_replace(['api.v1.', '.', '-'], ['', ' ', ' '], $route->getName())) : null,
            'parameters' => [...$this->pathParameters($route, $method), ...$this->queryParameters($method)],
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        if ($isPublic) {
            $operation['security'] = [];
        }

        if ($features = $this->featureKeys($route)) {
            $operation['description'] = trim(($operation['description'] ?? '')."\n\nFitur: `".implode('`, `', $features).'` (403 bila dimatikan di Pengaturan Fitur).');
        }

        $httpMethods = array_diff($route->methods(), ['HEAD', 'GET']);

        if ($httpMethods !== [] && ($body = $this->requestBody($method))) {
            $operation['requestBody'] = $body;
        }

        $operation['responses'] = $this->responses($route, $method, $isPublic, isset($operation['requestBody']));

        return $operation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pathParameters(Route $route, ReflectionMethod $method): array
    {
        $types = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $types[$parameter->getName()] = $type instanceof ReflectionNamedType ? $type->getName() : null;
        }

        return array_map(function (string $name) use ($types) {
            // Laravel binds {item_category} to an $itemCategory argument as well.
            $type = $types[$name] ?? $types[Str::camel($name)] ?? null;
            $isModel = $type !== null && is_subclass_of($type, Model::class);

            return array_filter([
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => $isModel || $type === 'int' ? 'integer' : 'string'],
                'description' => $isModel ? 'ID '.Str::headline(class_basename($type)) : null,
            ], fn ($value) => $value !== null);
        }, $route->parameterNames());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryParameters(ReflectionMethod $method): array
    {
        $parameters = [];

        foreach ($method->getAttributes(ApiQuery::class) as $attribute) {
            $query = $attribute->newInstance();
            $schema = $query->type === 'date' ? ['type' => 'string', 'format' => 'date'] : ['type' => $query->type];

            if ($query->enum) {
                $schema['enum'] = $query->enum;
            }

            $parameters[] = array_filter([
                'name' => $query->name,
                'in' => 'query',
                'required' => $query->required,
                'description' => $query->description ?: null,
                'schema' => $schema,
            ], fn ($value) => $value !== null);
        }

        if ($this->responseAttribute($method)?->paginated) {
            $parameters[] = ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1]];
            $parameters[] = ['name' => 'per_page', 'in' => 'query', 'required' => false, 'description' => 'Default 15, maksimum 100.', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(ReflectionMethod $method): ?array
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || ! is_subclass_of($type->getName(), FormRequest::class)) {
                continue;
            }

            $rules = $this->rulesOf($type->getName());

            if ($rules === []) {
                return null;
            }

            $builder = new RequestSchema;
            $schema = $builder->fromRules($rules);
            $contentType = $builder->hasFiles ? 'multipart/form-data' : 'application/json';

            return ['required' => true, 'content' => [$contentType => ['schema' => $schema]]];
        }

        return null;
    }

    /**
     * @param  class-string<FormRequest>  $class
     * @return array<string, mixed>
     */
    private function rulesOf(string $class): array
    {
        try {
            $request = $class::createFrom(Request::create('/'));
            $request->setContainer(app())->setUserResolver(fn () => null)->setRouteResolver(fn () => null);

            return method_exists($request, 'rules') ? app()->call([$request, 'rules']) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function responses(Route $route, ReflectionMethod $method, bool $isPublic, bool $hasBody): array
    {
        $attribute = $this->responseAttribute($method);
        $resource = $attribute?->resource ?? $this->returnedResource($method);
        $status = $attribute?->status ?? ($this->returnsNoContent($method) ? 204 : 200);
        $responses = [];

        if ($attribute?->mediaType) {
            $responses[(string) $status] = [
                'description' => $attribute->description ?? 'Berhasil.',
                'content' => [$attribute->mediaType => ['schema' => ['type' => 'string', 'format' => str_starts_with($attribute->mediaType, 'text/') ? null : 'binary']]],
            ];
            $responses[(string) $status]['content'][$attribute->mediaType]['schema'] = array_filter($responses[(string) $status]['content'][$attribute->mediaType]['schema']);
        } elseif ($status === 204) {
            $responses['204'] = ['description' => $attribute?->description ?? 'Berhasil, tanpa isi.'];
        } elseif ($resource !== null) {
            $item = $this->resources->ref($resource);
            $data = $attribute?->collection || $attribute?->paginated ? ['type' => 'array', 'items' => $item] : $item;
            $schema = ['type' => 'object', 'properties' => ['data' => $data], 'required' => ['data']];

            if ($attribute?->paginated) {
                $schema['properties'] += ['links' => ['$ref' => '#/components/schemas/PaginationLinks'], 'meta' => ['$ref' => '#/components/schemas/PaginationMeta']];
                $schema['required'] = ['data', 'links', 'meta'];
            }

            $responses[(string) $status] = ['description' => $attribute?->description ?? 'Berhasil.', 'content' => ['application/json' => ['schema' => $schema]]];
        } else {
            $responses[(string) $status] = ['description' => $attribute?->description ?? 'Berhasil.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message']]]];
        }

        if (! $isPublic) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
        }

        if ($route->parameterNames() !== []) {
            $responses['404'] = ['$ref' => '#/components/responses/NotFound'];
        }

        if ($hasBody || $route->methods() !== ['GET', 'HEAD']) {
            $responses['422'] = ['$ref' => '#/components/responses/ValidationError'];
        }

        return $responses;
    }

    private function responseAttribute(ReflectionMethod $method): ?ApiResponse
    {
        return ($method->getAttributes(ApiResponse::class)[0] ?? null)?->newInstance();
    }

    private function returnedResource(ReflectionMethod $method): ?string
    {
        $type = $method->getReturnType();

        if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), JsonResource::class)
            && ! (new ReflectionClass($type->getName()))->isAbstract()
            && ! str_contains($type->getName(), 'AnonymousResourceCollection')) {
            return $type->getName();
        }

        return null;
    }

    private function returnsNoContent(ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();

        return $type instanceof ReflectionNamedType && in_array($type->getName(), [Response::class, \Illuminate\Http\Response::class], true);
    }

    private function tagFor(ReflectionClass $class): string
    {
        $attribute = ($class->getAttributes(ApiTag::class)[0] ?? null)?->newInstance();
        $name = $attribute->name ?? Str::headline(Str::beforeLast($class->getShortName(), 'Controller'));

        $this->tags[$name] ??= [
            'name' => $name,
            'group' => $attribute->group ?? 'Lainnya',
            'description' => $attribute?->description,
        ];

        return $name;
    }

    /**
     * @return list<array{name: string, tags: list<string>}>
     */
    private function tagGroups(): array
    {
        $groups = [];

        foreach ($this->tags as $tag) {
            $groups[$tag['group']][] = $tag['name'];
        }

        return array_values(array_map(
            fn (string $group, array $tags) => ['name' => $group, 'tags' => $tags],
            array_keys($groups),
            $groups,
        ));
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function docSummary(ReflectionMethod $method): array
    {
        $doc = $method->getDocComment();

        if (! $doc) {
            return [Str::headline($method->getName()), null];
        }

        $lines = [];

        foreach (explode("\n", $doc) as $line) {
            $line = preg_replace('/^\s*\/?\*+\/?\s?/', '', rtrim($line));

            if (str_starts_with(trim($line), '@')) {
                break;
            }

            $lines[] = $line;
        }

        $text = trim(implode("\n", $lines));
        $paragraphs = preg_split('/\n\s*\n/', $text, 2);
        $summary = trim(preg_replace('/\s+/', ' ', $paragraphs[0] ?? ''));
        $description = isset($paragraphs[1]) ? trim(preg_replace('/(?<!\n)\n(?!\n)/', ' ', $paragraphs[1])) : null;

        return [$summary !== '' ? $summary : Str::headline($method->getName()), $description];
    }

    /**
     * @return list<string>
     */
    private function featureKeys(Route $route): array
    {
        $keys = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'feature:')) {
                array_push($keys, ...explode(',', Str::after($middleware, 'feature:')));
            }
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function standardSchemas(): array
    {
        return [
            'Message' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string']],
                'required' => ['message'],
            ],
            'ValidationError' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string', 'examples' => ['Stok tidak cukup.']],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'examples' => [['quantity' => ['Jumlah wajib diisi.']]],
                    ],
                ],
                'required' => ['message', 'errors'],
            ],
            'PaginationLinks' => [
                'type' => 'object',
                'properties' => [
                    'first' => ['type' => ['string', 'null']],
                    'last' => ['type' => ['string', 'null']],
                    'prev' => ['type' => ['string', 'null']],
                    'next' => ['type' => ['string', 'null']],
                ],
            ],
            'PaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'current_page' => ['type' => 'integer'],
                    'from' => ['type' => ['integer', 'null']],
                    'last_page' => ['type' => 'integer'],
                    'path' => ['type' => 'string'],
                    'per_page' => ['type' => 'integer'],
                    'to' => ['type' => ['integer', 'null']],
                    'total' => ['type' => 'integer'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function standardResponses(): array
    {
        $message = fn (string $description, string $example) => [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message'], 'example' => ['message' => $example]]],
        ];

        return [
            'Unauthenticated' => $message('Token tidak ada, salah, atau sudah dicabut.', 'Unauthenticated.'),
            'Forbidden' => $message('Peran/izin akun tidak mencukupi, atau fiturnya dimatikan.', 'This action is unauthorized.'),
            'NotFound' => $message('Data tidak ditemukan.', 'Data tidak ditemukan.'),
            'ValidationError' => [
                'description' => 'Input tidak valid atau aturan bisnis menolak aksi (mis. stok kurang, status dokumen tidak sesuai). Pesan aturan bisnis ada di `errors.message`.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]],
            ],
        ];
    }

    private function introduction(): string
    {
        return <<<'MD'
        REST API untuk aplikasi mobile. Semua endpoint berada di bawah `/api/v1` dan mengembalikan JSON.

        **Autentikasi**: login lewat `POST /api/v1/auth/login` (username / email / nomor HP + password) atau OTP WhatsApp, lalu kirim token di header `Authorization: Bearer <token>`. Sertakan juga `Accept: application/json`.

        **Hak akses** mengikuti peran & izin yang sama dengan aplikasi web. `GET /api/v1/auth/me` mengembalikan peran, izin, dan fitur yang aktif agar aplikasi bisa menyembunyikan menu yang tidak boleh diakses.

        **Format**: nominal uang dan kuantitas dikirim sebagai string desimal (mis. `"1250.50"`) supaya tidak kehilangan presisi. Tanggal `YYYY-MM-DD`, waktu ISO 8601. Daftar memakai paginasi `page` & `per_page` dengan `links` dan `meta`.

        **Error**: `401` token tidak valid, `403` tidak berwenang / fitur dimatikan, `404` data tidak ada, `422` validasi atau aturan bisnis (pesan di `message` dan `errors`), `429` terlalu banyak request (120/menit per akun, login & OTP 10/menit).

        **Aksi yang tersedia**: respons detail dokumen menyertakan `abilities` (mis. `approve`, `cancel`, `receive`) yang sudah memperhitungkan peran dan status dokumen, jadi aplikasi cukup menampilkan tombol yang bernilai `true`.

        **Realtime (opsional)**: event yang sama dengan web disiarkan lewat Reverb (protokol Pusher). Otorisasi channel privat memakai `POST /api/broadcasting/auth` dengan header Bearer. Channel: `private-App.Models.User.{id}` (notifikasi) dan `private-dashboard` (perubahan dokumen).

        **Cetak**: endpoint `/api/v1/print/...` mengembalikan HTML dokumen cetak yang sama dengan web untuk ditampilkan di WebView.
        MD;
    }
}

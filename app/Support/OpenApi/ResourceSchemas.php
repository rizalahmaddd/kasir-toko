<?php

namespace App\Support\OpenApi;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;

/**
 * Builds components.schemas from the `@return array{...}` shape documented on each resource's
 * toArray(). The docblock is the single source of truth for the response contract.
 */
class ResourceSchemas
{
    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    public function __construct(private TypeParser $parser = new TypeParser) {}

    /**
     * @param  class-string<JsonResource>  $resource
     * @return array{'$ref': string}
     */
    public function ref(string $resource): array
    {
        $name = $this->schemaName($resource);

        if (! array_key_exists($name, $this->schemas)) {
            $this->schemas[$name] = [];
            $this->schemas[$name] = $this->build($resource);
        }

        return ['$ref' => "#/components/schemas/{$name}"];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        ksort($this->schemas);

        return $this->schemas;
    }

    public function schemaName(string $resource): string
    {
        return Str::beforeLast(class_basename($resource), 'Resource');
    }

    /**
     * @param  class-string<JsonResource>  $resource
     * @return array<string, mixed>
     */
    private function build(string $resource): array
    {
        $reflection = new ReflectionClass($resource);
        $docComment = $reflection->getMethod('toArray')->getDocComment() ?: '';

        if (! preg_match('/@return\s+(.+?)(?=\n\s*\*\s*@|\*\/)/s', $docComment, $match)) {
            throw new RuntimeException("{$resource}::toArray() needs an @return array{...} shape for the API docs.");
        }

        $type = preg_replace('/^\s*\*\s?/m', '', $match[1]);
        $schema = $this->toSchema($this->parser->parse(trim($type)), $reflection);

        if ($description = $this->classSummary($reflection)) {
            $schema['description'] = $description;
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function toSchema(array $node, ReflectionClass $context, ?string $field = null): array
    {
        return match ($node['kind']) {
            'shape' => $this->shapeSchema($node['fields'], $context),
            'list' => ['type' => 'array', 'items' => $this->toSchema($node['of'], $context)],
            'map' => ['type' => 'object', 'additionalProperties' => $this->toSchema($node['of'], $context)],
            'union' => $this->unionSchema($node['types'], $context, $field),
            default => $this->scalarSchema($node['name'], $context, $field),
        };
    }

    /**
     * @param  array<string, array{type: array<string, mixed>, optional: bool}>  $fields
     * @return array<string, mixed>
     */
    private function shapeSchema(array $fields, ReflectionClass $context): array
    {
        $properties = [];
        $required = [];

        foreach ($fields as $key => $field) {
            $properties[$key] = $this->toSchema($field['type'], $context, $key);

            if (! $field['optional']) {
                $required[] = $key;
            }
        }

        return array_filter(['type' => 'object', 'properties' => $properties, 'required' => $required]);
    }

    /**
     * @param  list<array<string, mixed>>  $types
     * @return array<string, mixed>
     */
    private function unionSchema(array $types, ReflectionClass $context, ?string $field): array
    {
        $nullable = false;
        $schemas = [];

        foreach ($types as $type) {
            if (($type['name'] ?? null) === 'null') {
                $nullable = true;

                continue;
            }

            $schemas[] = $this->toSchema($type, $context, $field);
        }

        $schema = count($schemas) === 1 ? $schemas[0] : ['anyOf' => $schemas];

        if (! $nullable) {
            return $schema;
        }

        if (isset($schema['$ref']) || isset($schema['anyOf'])) {
            return ['anyOf' => [...($schema['anyOf'] ?? [$schema]), ['type' => 'null']]];
        }

        $schema['type'] = [$schema['type'], 'null'];

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function scalarSchema(string $name, ReflectionClass $context, ?string $field): array
    {
        $schema = match (strtolower($name)) {
            'int', 'integer', 'positive-int', 'non-negative-int' => ['type' => 'integer'],
            'float', 'double' => ['type' => 'number'],
            'numeric-string' => ['type' => 'string', 'format' => 'decimal', 'examples' => ['1250.50']],
            'bool', 'boolean', 'true', 'false' => ['type' => 'boolean'],
            'string', 'non-empty-string', 'lowercase-string' => ['type' => 'string'],
            'array' => ['type' => 'object'],
            'mixed' => ['description' => 'Nilai bebas.'],
            default => null,
        };

        if ($schema === null) {
            return $this->ref($this->resolveClass($name, $context));
        }

        if (($schema['type'] ?? null) === 'string' && $field !== null && ! isset($schema['format'])) {
            if ($field === 'date' || str_ends_with($field, '_date')) {
                $schema['format'] = 'date';
            } elseif (str_ends_with($field, '_at')) {
                $schema['format'] = 'date-time';
            } elseif (str_ends_with($field, '_url') || $field === 'url') {
                $schema['format'] = 'uri';
            }
        }

        return $schema;
    }

    private function resolveClass(string $name, ReflectionClass $context): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $source = (string) file_get_contents((string) $context->getFileName());

        if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+\\\\'.preg_quote($name, '/').');/m', $source, $match)) {
            return $match[1];
        }

        $sameNamespace = $context->getNamespaceName().'\\'.$name;

        if (class_exists($sameNamespace)) {
            return $sameNamespace;
        }

        throw new RuntimeException("Cannot resolve type [{$name}] used in {$context->getName()}::toArray().");
    }

    private function classSummary(ReflectionClass $reflection): ?string
    {
        $doc = $reflection->getDocComment();

        if (! $doc) {
            return null;
        }

        $lines = array_filter(array_map(
            fn (string $line) => trim(preg_replace('/^\s*\/?\*+\/?/', '', $line)),
            explode("\n", $doc),
        ), fn (string $line) => $line !== '' && ! str_starts_with($line, '@'));

        return $lines === [] ? null : implode(' ', $lines);
    }
}

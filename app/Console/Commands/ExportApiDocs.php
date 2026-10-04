<?php

namespace App\Console\Commands;

use App\Support\OpenApi\OpenApiGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api:docs {--output=storage/app/openapi.json : Where to write the OpenAPI document}')]
#[Description('Export the mobile REST API OpenAPI document (e.g. for client code generation)')]
class ExportApiDocs extends Command
{
    public function handle(OpenApiGenerator $generator): int
    {
        $path = base_path($this->option('output'));
        $document = $generator->generate();

        file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->components->info(sprintf('%d endpoints written to %s', collect($document['paths'])->flatten(1)->count(), $path));

        return self::SUCCESS;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OpenApi\OpenApiGenerator;
use Illuminate\Http\JsonResponse;

class OpenApiDocumentController extends Controller
{
    public function __invoke(OpenApiGenerator $generator): JsonResponse
    {
        return response()->json($generator->generate(), options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\Tone;
use App\Http\Requests\GenerateEmailRequest;
use App\Services\EmailTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

class EmailTemplateController extends Controller
{
    public function __invoke(GenerateEmailRequest $request, EmailTemplateService $service): JsonResponse
    {
        $template = $service->generate(
            purpose: $request->validated('purpose'),
            recipientName: $request->validated('recipient_name'),
            tone: $request->enum('tone', Tone::class),
        );

        $data = $template->toArray();
        $data['meta']['request_id'] = Context::get('request_id');

        return response()->json($data);
    }
}

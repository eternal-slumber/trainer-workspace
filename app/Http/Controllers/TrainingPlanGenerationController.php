<?php

namespace App\Http\Controllers;

use App\Models\TrainingPlanGeneration;
use App\Services\Agent\TrainingAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrainingPlanGenerationController extends Controller
{
    public function show(Request $request, TrainingPlanGeneration $trainingPlanGeneration): JsonResponse
    {
        abort_unless($request->user()->id === $trainingPlanGeneration->user_id, 404);

        return response()->json(['generation' => $trainingPlanGeneration->receipt()]);
    }

    public function cancel(Request $request, TrainingPlanGeneration $trainingPlanGeneration, TrainingAgentService $service): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->id === $trainingPlanGeneration->user_id, 404);
        $request->validate(['acknowledge_unknown' => ['sometimes', 'boolean']]);
        $service->cancelGeneration($trainingPlanGeneration->id, $request->boolean('acknowledge_unknown'));

        return $request->expectsJson()
            ? response()->json(['generation' => $trainingPlanGeneration->fresh()->receipt()])
            : back();
    }
}

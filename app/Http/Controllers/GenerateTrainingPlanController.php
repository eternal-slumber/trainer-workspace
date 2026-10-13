<?php

namespace App\Http\Controllers;

use App\Models\ScheduledTraining;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class GenerateTrainingPlanController extends Controller
{
    public function __invoke(ScheduledTraining $scheduledTraining): JsonResponse
    {
        Gate::authorize('generateTrainingPlan', $scheduledTraining);

        return response()->json(['message' => 'Синхронная генерация закрыта. Используйте training-plan-generations с request_key.'], 410);
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\TrainingPlans\ReserveTrainingPlanGeneration;
use App\Http\Requests\ReserveTrainingPlanGenerationRequest;
use App\Models\ScheduledTraining;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ReserveTrainingPlanGenerationController extends Controller
{
    public function __invoke(
        ReserveTrainingPlanGenerationRequest $request,
        ScheduledTraining $scheduledTraining,
        ReserveTrainingPlanGeneration $reserve,
    ): JsonResponse|RedirectResponse {
        $generation = $reserve->execute($request->user(), $scheduledTraining, $request->validated('request_key'));

        return $request->expectsJson()
            ? response()->json(['generation' => $generation->receipt()], 202)
            : to_route('scheduled-trainings.show', $scheduledTraining);
    }
}

<?php

use App\Actions\TrainingPlans\ReserveTrainingPlanGeneration;
use App\Models\ScheduledTraining;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql'
    || ! preg_match('/^testing(?:_test_\d+)?$/', DB::connection()->getDatabaseName())) {
    throw new LogicException('Concurrency worker requires a PostgreSQL testing database.');
}

DB::select("select set_config('application_name', ?, false)", [$argv[4]]);
try {
    $generation = app(ReserveTrainingPlanGeneration::class)->execute(
        User::query()->findOrFail((int) $argv[1]),
        ScheduledTraining::query()->findOrFail((int) $argv[2]),
        $argv[3],
    );
    echo json_encode(['status' => 202, 'id' => $generation->id]);
} catch (ValidationException $exception) {
    echo json_encode(['status' => 422, 'errors' => array_keys($exception->errors())]);
}

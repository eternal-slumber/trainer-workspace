<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $trainee_id
 * @property int|null $training_group_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $location
 * @property string $status
 * @property string $color
 * @property string|null $notes
 * @property-read User $user
 * @property-read Trainee|null $trainee
 * @property-read TrainingGroup|null $trainingGroup
 */
#[Fillable(['trainee_id', 'training_group_id', 'starts_at', 'ends_at', 'location', 'status', 'color', 'notes'])]
class ScheduledTraining extends Model
{
    /**
     * @var list<string>
     */
    public const STATUSES = ['planned', 'completed', 'cancelled'];

    /**
     * @var list<string>
     */
    public const COLORS = ['blue', 'green', 'orange', 'purple', 'red', 'gray'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'planned',
        'color' => 'blue',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Trainee, $this>
     */
    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    /**
     * @return BelongsTo<TrainingGroup, $this>
     */
    public function trainingGroup(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class);
    }

    /** @return HasOne<TrainingPlan, $this> */
    public function trainingPlan(): HasOne
    {
        return $this->hasOne(TrainingPlan::class);
    }

    /** @return HasOne<TrainingSession, $this> */
    public function trainingSession(): HasOne
    {
        return $this->hasOne(TrainingSession::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'age', 'level', 'goal', 'restrictions', 'notes'])]
class Trainee extends Model
{
    /** @param Builder<Trainee> $query
     * @return Builder<Trainee>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('archived_at'));
    }

    public function archive(): void
    {
        $this->newQuery()->whereKey($this->getKey())->whereNull('archived_at')
            ->update(['archived_at' => now()]);
    }

    public function restore(): void
    {
        $this->newQuery()->whereKey($this->getKey())->whereNotNull('archived_at')
            ->update(['archived_at' => null]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ScheduledTraining, $this>
     */
    public function scheduledTrainings(): HasMany
    {
        return $this->hasMany(ScheduledTraining::class);
    }

    /** @return HasMany<TrainingPlan, $this> */
    public function trainingPlans(): HasMany
    {
        return $this->hasMany(TrainingPlan::class);
    }

    /** @return HasMany<TrainingNote, $this> */
    public function trainingNotes(): HasMany
    {
        return $this->hasMany(TrainingNote::class);
    }

    /** @return HasMany<AgentMemory, $this> */
    public function agentMemories(): HasMany
    {
        return $this->hasMany(AgentMemory::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }
}

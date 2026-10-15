<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sport_type', 'age_range', 'level', 'goal', 'restrictions', 'notes'])]
class TrainingGroup extends Model
{
    /** @param Builder<TrainingGroup> $query
     * @return Builder<TrainingGroup>
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
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

    /** @return HasMany<TrainingSession, $this> */
    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
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
}

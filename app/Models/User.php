<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $working_day_starts_at
 * @property string $working_day_ends_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'working_day_starts_at', 'working_day_ends_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, string> */
    protected $attributes = [
        'working_day_starts_at' => '08:00:00',
        'working_day_ends_at' => '22:00:00',
    ];

    /**
     * @return HasMany<Trainee, $this>
     */
    public function trainees(): HasMany
    {
        return $this->hasMany(Trainee::class);
    }

    /**
     * @return HasMany<TrainingGroup, $this>
     */
    public function trainingGroups(): HasMany
    {
        return $this->hasMany(TrainingGroup::class);
    }

    /**
     * @return HasMany<ScheduledTraining, $this>
     */
    public function scheduledTrainings(): HasMany
    {
        return $this->hasMany(ScheduledTraining::class);
    }

    /**
     * @return HasMany<Exercise, $this>
     */
    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class);
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

    /** @return HasMany<AiRequestLog, $this> */
    public function aiRequestLogs(): HasMany
    {
        return $this->hasMany(AiRequestLog::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

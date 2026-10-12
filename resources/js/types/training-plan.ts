import type { ScheduledTrainingStatus } from './scheduled-training';

export type TrainingPlanStatus = 'draft' | 'approved' | 'completed';
export type TrainingNoteIntensity = 'low' | 'medium' | 'high';
export type TrainingNoteResult = 'bad' | 'normal' | 'good';

export type TrainingNote = {
    id: number;
    intensity: TrainingNoteIntensity;
    result: TrainingNoteResult;
    tags: string[];
    note: string;
};

export type TrainingPlanExercise = {
    id?: number;
    exercise_id: number | null;
    name: string;
    description: string | null;
    duration_minutes: number | null;
    sets: number | null;
    repetitions: string | null;
    rest_seconds: number | null;
    position: number;
    notes: string | null;
};

export type TrainingPlanBlock = {
    id?: number;
    name: string;
    duration_minutes: number;
    position: number;
    notes: string | null;
    exercises: TrainingPlanExercise[];
};

export type TrainingPlan = {
    id: number;
    scheduled_training_id: number;
    trainee_id: number | null;
    training_group_id: number | null;
    title: string;
    goal: string;
    total_duration_minutes: number;
    status: TrainingPlanStatus;
    source: 'manual' | 'ai';
    notes: string | null;
    warnings: string[];
    ai_reasoning: string | null;
    training_note: TrainingNote | null;
    subject_name: string;
    scheduled_training: ScheduledTrainingPlanOption;
    blocks: TrainingPlanBlock[];
};

export type TrainingPlanListItem = Pick<
    TrainingPlan,
    | 'id'
    | 'title'
    | 'goal'
    | 'total_duration_minutes'
    | 'status'
    | 'source'
    | 'subject_name'
    | 'scheduled_training'
> & {
    blocks_count: number;
};

export type ScheduledTrainingPlanOption = {
    id: number;
    subject_name?: string;
    starts_at: string;
    ends_at: string;
    location: string;
    status: ScheduledTrainingStatus;
};

export type ExercisePlanOption = {
    id: number;
    name: string;
    description: string;
    duration_minutes: number | null;
    is_system: boolean;
};

export type TrainingHistoryItem = {
    id: number;
    title: string;
    goal: string;
    total_duration_minutes: number;
    status: 'completed';
    starts_at: string;
    training_note: Omit<TrainingNote, 'id'> | null;
};

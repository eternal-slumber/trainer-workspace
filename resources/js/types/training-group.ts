export type TrainingGroup = {
    id: number;
    name: string;
    sport_type: string;
    age_range: string;
    level: string;
    goal: string;
    restrictions: string | null;
    notes: string | null;
    archived_at: string | null;
};

export type TrainingGroupListItem = Pick<
    TrainingGroup,
    'id' | 'name' | 'sport_type' | 'age_range' | 'level' | 'goal'
>;

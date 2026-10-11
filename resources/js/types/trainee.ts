export type Trainee = {
    id: number;
    name: string;
    age: number | null;
    level: string;
    goal: string;
    restrictions: string | null;
    notes: string | null;
    archived_at: string | null;
};

export type TraineeListItem = Pick<
    Trainee,
    'id' | 'name' | 'age' | 'level' | 'goal'
>;

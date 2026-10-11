<?php

use App\Models\TrainingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests cannot access training group pages', function () {
    $this->get(route('training-groups.index'))->assertRedirect(route('login'));
    $this->get(route('training-groups.create'))->assertRedirect(route('login'));
});

test('a user sees only their training groups in the list', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $user->trainingGroups()->create(trainingGroupPayload(['name' => 'Своя группа']));
    $otherUser->trainingGroups()->create(trainingGroupPayload(['name' => 'Чужая группа']));

    $this->actingAs($user)
        ->get(route('training-groups.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('training-groups/index')
            ->has('trainingGroups', 1)
            ->where('trainingGroups.0.name', 'Своя группа'));
});

test('a user can open the create training group page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('training-groups.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('training-groups/create'));
});

test('a user can create a training group owned by themselves', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $response = $this->actingAs($user)->post(route('training-groups.store'), [
        ...trainingGroupPayload(),
        'user_id' => $otherUser->id,
    ]);

    $trainingGroup = TrainingGroup::query()->sole();

    $response->assertRedirect(route('training-groups.show', $trainingGroup));
    expect($trainingGroup->user->is($user))->toBeTrue();
});

test('a user can view and edit their training group', function () {
    $user = User::factory()->create();
    $trainingGroup = $user->trainingGroups()->create(trainingGroupPayload());

    $this->actingAs($user)
        ->get(route('training-groups.show', $trainingGroup))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('training-groups/show')
            ->where('trainingGroup.id', $trainingGroup->id)
            ->where('trainingGroup.name', 'Группа U12'));

    $this->actingAs($user)
        ->get(route('training-groups.edit', $trainingGroup))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('training-groups/edit')
            ->where('trainingGroup.id', $trainingGroup->id));
});

test('a user can update their training group', function () {
    $user = User::factory()->create();
    $trainingGroup = $user->trainingGroups()->create(trainingGroupPayload());

    $this->actingAs($user)
        ->patch(
            route('training-groups.update', $trainingGroup),
            trainingGroupPayload(['goal' => 'Новая цель группы']),
        )
        ->assertRedirect(route('training-groups.show', $trainingGroup));

    expect($trainingGroup->fresh()?->goal)->toBe('Новая цель группы');
});

test('a user can archive their training group', function () {
    $user = User::factory()->create();
    $trainingGroup = $user->trainingGroups()->create(trainingGroupPayload());

    $this->actingAs($user)
        ->delete(route('training-groups.destroy', $trainingGroup))
        ->assertRedirect(route('training-groups.index'));

    $this->assertModelExists($trainingGroup);
    expect($trainingGroup->fresh()->archived_at)->not->toBeNull();
});

test('a user cannot access another users training group', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $trainingGroup = $otherUser->trainingGroups()->create(trainingGroupPayload());

    $this->actingAs($user)
        ->get(route('training-groups.show', $trainingGroup))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('training-groups.edit', $trainingGroup))
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(
            route('training-groups.update', $trainingGroup),
            trainingGroupPayload(['name' => 'Взлом']),
        )
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('training-groups.destroy', $trainingGroup))
        ->assertNotFound();

    expect($trainingGroup->fresh()?->name)->toBe('Группа U12');
});

test('training group data is validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('training-groups.store'), trainingGroupPayload(['sport_type' => '']))
        ->assertSessionHasErrors('sport_type');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function trainingGroupPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Группа U12',
        'sport_type' => 'Функциональный тренинг',
        'age_range' => '10–12 лет',
        'level' => 'Средний',
        'goal' => 'Развить выносливость',
        'restrictions' => null,
        'notes' => null,
    ], $overrides);
}

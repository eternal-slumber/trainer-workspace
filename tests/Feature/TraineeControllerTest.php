<?php

use App\Models\Trainee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests cannot access trainee pages', function () {
    $this->get(route('trainees.index'))->assertRedirect(route('login'));
    $this->get(route('trainees.create'))->assertRedirect(route('login'));
});

test('a user sees only their trainees in the list', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $user->trainees()->create(traineePayload(['name' => 'Свой клиент']));
    $otherUser->trainees()->create(traineePayload(['name' => 'Чужой клиент']));

    $this->actingAs($user)
        ->get(route('trainees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('trainees/index')
            ->has('trainees', 1)
            ->where('trainees.0.name', 'Свой клиент'));
});

test('a user can open the create trainee page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('trainees.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('trainees/create'));
});

test('a user can create a trainee owned by themselves', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $response = $this->actingAs($user)->post(route('trainees.store'), [
        ...traineePayload(),
        'user_id' => $otherUser->id,
    ]);

    $trainee = Trainee::query()->sole();

    $response->assertRedirect(route('trainees.show', $trainee));
    expect($trainee->user->is($user))->toBeTrue();
});

test('a user can view and edit their trainee', function () {
    $user = User::factory()->create();
    $trainee = $user->trainees()->create(traineePayload());

    $this->actingAs($user)
        ->get(route('trainees.show', $trainee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('trainees/show')
            ->where('trainee.id', $trainee->id)
            ->where('trainee.name', 'Алексей Смирнов'));

    $this->actingAs($user)
        ->get(route('trainees.edit', $trainee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('trainees/edit')
            ->where('trainee.id', $trainee->id));
});

test('a user can update their trainee', function () {
    $user = User::factory()->create();
    $trainee = $user->trainees()->create(traineePayload());

    $this->actingAs($user)
        ->patch(route('trainees.update', $trainee), traineePayload(['goal' => 'Новая цель']))
        ->assertRedirect(route('trainees.show', $trainee));

    expect($trainee->fresh()?->goal)->toBe('Новая цель');
});

test('a user can archive their trainee', function () {
    $user = User::factory()->create();
    $trainee = $user->trainees()->create(traineePayload());

    $this->actingAs($user)
        ->delete(route('trainees.destroy', $trainee))
        ->assertRedirect(route('trainees.index'));

    $this->assertModelExists($trainee);
    expect($trainee->fresh()->archived_at)->not->toBeNull();
});

test('a user cannot access another users trainee', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $trainee = $otherUser->trainees()->create(traineePayload());

    $this->actingAs($user)
        ->get(route('trainees.show', $trainee))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('trainees.edit', $trainee))
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(route('trainees.update', $trainee), traineePayload(['name' => 'Взлом']))
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('trainees.destroy', $trainee))
        ->assertNotFound();

    expect($trainee->fresh()?->name)->toBe('Алексей Смирнов');
});

test('trainee data is validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('trainees.store'), traineePayload(['name' => '', 'age' => 0]))
        ->assertSessionHasErrors(['name', 'age']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function traineePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Алексей Смирнов',
        'age' => 28,
        'level' => 'Начинающий',
        'goal' => 'Улучшить общую физическую форму',
        'restrictions' => null,
        'notes' => null,
    ], $overrides);
}

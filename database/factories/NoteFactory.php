<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NoteVisibility;
use App\Models\Note;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    protected $model = Note::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'notable_type' => null,
            'notable_id' => null,
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'visibility' => NoteVisibility::Shared,
            'archived_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->getKey(),
        ]);
    }

    public function author(User $user): self
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->getKey(),
        ]);
    }

    public function private(): self
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => NoteVisibility::Private,
        ]);
    }

    public function shared(): self
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => NoteVisibility::Shared,
        ]);
    }

    public function archived(): self
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => $this->faker->dateTime(),
        ]);
    }
}

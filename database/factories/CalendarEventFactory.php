<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NoteVisibility;
use App\Models\CalendarEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    protected $model = CalendarEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-1 week', '+1 week');

        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'eventable_type' => null,
            'eventable_id' => null,
            'title' => $this->faker->sentence(4),
            'description' => null,
            'starts_at' => $start,
            'ends_at' => (clone $start)->modify('+1 hour'),
            'all_day' => false,
            'visibility' => NoteVisibility::Shared,
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

    public function allDay(): self
    {
        return $this->state(function (array $attributes) {
            $start = $attributes['starts_at'] ?? now()->startOfDay();

            return [
                'all_day' => true,
                'starts_at' => $start,
                'ends_at' => (clone $start)->modify('+1 day'),
            ];
        });
    }
}

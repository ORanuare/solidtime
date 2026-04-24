<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpdateTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_update_page_shows_not_found_if_id_is_not_uuid(): void
    {
        // Arrange
        $user = User::factory()->withPersonalOrganization()->create();
        $this->actingAs($user);

        // Act
        $response = $this->get('/teams/1');

        // Assert
        $response->assertStatus(404);
    }

    public function test_team_names_can_be_updated(): void
    {
        // Arrange
        $user = User::factory()->withPersonalOrganization()->create();
        $this->actingAs($user);

        // Act
        $response = $this->put('/teams/'.$user->currentTeam->id, [
            'name' => 'Test Organization',
            'currency' => 'USD',
        ]);

        // Assert
        $response->assertValid(errorBag: 'updateTeamName');
        $this->assertCount(1, $user->fresh()->ownedTeams);
        $organization = $user->currentTeam->fresh();
        $this->assertEquals('Test Organization', $organization->name);
        $this->assertEquals('USD', $organization->currency);
    }

    public function test_team_profile_photo_can_be_uploaded(): void
    {
        Storage::fake('public');

        $user = User::factory()->withPersonalOrganization()->create();
        $this->actingAs($user);

        $organization = $user->currentTeam;
        $file = UploadedFile::fake()->image('logo.jpg', 100, 100);

        $response = $this->post('/teams/'.$organization->id, [
            '_method' => 'PUT',
            'name' => $organization->name,
            'currency' => $organization->currency,
            'photo' => $file,
        ]);

        $response->assertValid(errorBag: 'updateTeamName');
        $organization->refresh();
        $this->assertNotNull($organization->profile_photo_path);
        Storage::disk('public')->assertExists($organization->profile_photo_path);
    }

    public function test_team_profile_photo_can_be_removed(): void
    {
        Storage::fake('public');

        $user = User::factory()->withPersonalOrganization()->create();
        $organization = $user->currentTeam;
        $path = 'organization-photos/test.jpg';
        Storage::disk('public')->put($path, 'fake-content');
        $organization->forceFill(['profile_photo_path' => $path])->save();

        $this->actingAs($user);

        $response = $this->delete('/teams/'.$organization->id.'/profile-photo');

        $response->assertStatus(303);
        $organization->refresh();
        $this->assertNull($organization->profile_photo_path);
        Storage::disk('public')->assertMissing($path);
    }
}

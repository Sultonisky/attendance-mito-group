<?php

namespace Tests\Feature\Notification;

use App\Models\LeaveRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_guest_cannot_view_notifications(): void
    {
        $this->getJson('/api/v1/notifications')
            ->assertUnauthorized();
    }

    public function test_admin_cannot_view_notifications(): void
    {
        $admin = $this->makeUser('ADMIN');

        $this->actingAs($admin)
            ->getJson('/api/v1/notifications')
            ->assertForbidden();
    }

    public function test_user_cannot_view_notifications(): void
    {
        $user = $this->makeUser('USER');

        $this->actingAs($user)
            ->getJson('/api/v1/notifications')
            ->assertForbidden();
    }

    public function test_super_admin_can_list_and_mark_notifications(): void
    {
        $superAdmin = $this->makeUser('SUPER_ADMIN');

        LeaveRequest::factory()->create(['status' => 'pending']);

        $list = $this->actingAs($superAdmin)
            ->getJson('/api/v1/notifications');

        $list->assertOk();
        $list->assertJsonPath('success', true);
        $this->assertGreaterThanOrEqual(1, count($list->json('data')));
        $this->assertGreaterThanOrEqual(1, (int) $list->json('meta.unread_count'));

        $notificationId = $list->json('data.0.id');
        $this->assertNotEmpty($notificationId);

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('data.unread', false);

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->actingAs($superAdmin)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_admin_cannot_mark_notifications_read(): void
    {
        $superAdmin = $this->makeUser('SUPER_ADMIN');
        $admin = $this->makeUser('ADMIN');

        LeaveRequest::factory()->create(['status' => 'pending']);

        $list = $this->actingAs($superAdmin)->getJson('/api/v1/notifications');
        $notificationId = $list->json('data.0.id');

        $this->actingAs($admin)
            ->postJson("/api/v1/notifications/{$notificationId}/read")
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson('/api/v1/notifications/read-all')
            ->assertForbidden();
    }
}

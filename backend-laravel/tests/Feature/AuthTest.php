<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Headers that make the test request look like it originates from the
     * first-party SPA so Sanctum applies the stateful (session) flow.
     *
     * @return array<string, string>
     */
    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ];
    }

    /**
     * Reset resolved auth guards to simulate a fresh HTTP request lifecycle.
     *
     * In feature tests the container (and therefore the auth manager with its
     * resolved RequestGuard instances) persists across requests. Real HTTP
     * requests boot a fresh container per request, so this reset keeps the
     * session-cookie flow honest.
     */
    private function freshRequestLifecycle(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * Create a user for authentication tests.
     */
    private function test_user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'member@example.com',
            'password' => 'secret-password',
        ], $attributes));
    }

    /**
     * Unauthenticated requests to protected endpoints receive 401.
     */
    public function test_unauthenticated_request_to_protected_endpoint_returns_401(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    /**
     * Login validation failures return 422.
     */
    public function test_login_returns_validation_error_for_missing_data(): void
    {
        $this->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    /**
     * Invalid credentials return 422, never 500, and never leak internals.
     */
    public function test_login_rejects_invalid_credentials(): void
    {
        $this->test_user();

        $response = $this->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/login', [
                'email' => 'member@example.com',
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);

        $content = $response->getContent();

        $this->assertStringNotContainsString('Exception', $content);
        $this->assertStringNotContainsString('stack', strtolower($content));
    }

    /**
     * Valid credentials authenticate the user and return a safe payload.
     */
    public function test_login_authenticates_user_and_returns_safe_payload(): void
    {
        $this->test_user(['name' => 'Example User']);

        $response = $this->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/login', [
                'email' => 'member@example.com',
                'password' => 'secret-password',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.user.email', 'member@example.com');
        $response->assertJsonPath('data.user.name', 'Example User');

        $user = $response->json('data.user');

        $this->assertArrayNotHasKey('password', $user);
        $this->assertArrayNotHasKey('remember_token', $user);

        $content = $response->getContent();

        $this->assertStringNotContainsString('secret-password', $content);
        $this->assertStringNotContainsString('$2y$', $content);
    }

    /**
     * The authenticated user's profile is returned by /auth/me.
     */
    public function test_auth_me_returns_current_user(): void
    {
        $user = $this->test_user(['name' => 'Example User']);

        $this->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/login', [
                'email' => 'member@example.com',
                'password' => 'secret-password',
            ])->assertOk();

        $this->freshRequestLifecycle();

        $response = $this->withHeaders($this->spaHeaders())
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $user->id);
        $response->assertJsonPath('data.user.email', $user->email);
    }

    /**
     * /auth/me never exposes sensitive data.
     */
    public function test_auth_me_never_exposes_sensitive_data(): void
    {
        $this->test_user();

        $this->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/login', [
                'email' => 'member@example.com',
                'password' => 'secret-password',
            ])->assertOk();

        $this->freshRequestLifecycle();

        $content = $this->withHeaders($this->spaHeaders())
            ->getJson('/api/v1/auth/me')
            ->getContent();

        $this->assertStringNotContainsString('password', strtolower($content));
        $this->assertStringNotContainsString('remember_token', $content);
        $this->assertStringNotContainsString('$2y$', $content);
    }

    /**
     * Logout invalidates the session.
     */
    public function test_logout_invalidates_the_session(): void
    {
        $this->test_user();

        $this->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/login', [
                'email' => 'member@example.com',
                'password' => 'secret-password',
            ])->assertOk();

        $this->assertAuthenticated();

        $logoutResponse = $this->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/logout');

        $logoutResponse->assertOk();

        $this->freshRequestLifecycle();

        $this->withHeaders($this->spaHeaders())
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    /**
     * Logout requires authentication.
     */
    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/logout')
            ->assertUnauthorized();
    }
}

<?php

namespace Tests\Concerns;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Users\Contracts\UserRole;
use Modules\Users\Models\User;

/**
 * Helpers for API tests. Tests live outside the modules, so importing the User model here is allowed.
 */
trait InteractsWithApi
{
    protected function makeUser(UserRole $role = UserRole::Customer): User
    {
        $user = User::create([
            'name' => 'Test '.$role->value,
            'email' => Str::lower(Str::random(10)).'@shop.test',
            'password' => 'password',
        ]);

        // role is deliberately not in $fillable.
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    /**
     * Send a request as the given user, or as a guest when null.
     *
     * The guard is dropped first: the test client reuses one application, so a guard that already
     * authenticated a previous request would otherwise keep answering with the old user.
     *
     * @param  array<string, mixed>  $data
     */
    protected function api(?User $user, string $method, string $uri, array $data = []): TestResponse
    {
        app('auth')->forgetGuards();

        $headers = $user === null ? [] : ['Authorization' => 'Bearer '.auth('api')->login($user)];

        app('auth')->forgetGuards();

        return $this->withHeaders($headers)->json($method, '/api/v1/'.ltrim($uri, '/'), $data);
    }

    /**
     * Asserts the unified error envelope from PROJECT_RULES section 2 and that nothing internal leaks.
     */
    protected function assertApiError(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']])
            ->assertJsonPath('error.code', $code);

        $body = $response->json();
        $this->assertSame(['error'], array_keys($body), 'An error response holds only the error key.');

        foreach (['trace', 'exception', 'file', 'line'] as $leak) {
            $this->assertArrayNotHasKey($leak, $body);
            $this->assertArrayNotHasKey($leak, $body['error']);
        }
    }
}

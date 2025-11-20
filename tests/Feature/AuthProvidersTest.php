<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class AuthProvidersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test user registration sets 'local' provider
     */
    public function test_manual_registration_sets_local_provider()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '081234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals(['local'], $user->auth_providers);
    }

    /**
     * Test Google login for existing manual user doesn't overwrite name
     */
    public function test_google_login_does_not_overwrite_manual_user_name()
    {
        // Create manual user
        $user = User::create([
            'name' => 'Manual Name',
            'email' => 'user@example.com',
            'phone' => '081234567890',
            'password' => Hash::make('password'),
            'auth_providers' => ['local'],
            'role_id' => 2,
        ]);

        // Simulate Google login (would normally come from Firebase)
        // We'll test the createOrFindFirebaseUser logic directly
        $controller = new \App\Http\Controllers\AuthController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('createOrFindFirebaseUser');
        $method->setAccessible(true);

        $response = $method->invoke(
            $controller,
            'google_uid_123',
            'user@example.com',
            'Google Name', // Different from manual name
            'https://example.com/picture.jpg'
        );

        // Reload user
        $user->refresh();

        // Assert name is NOT changed
        $this->assertEquals('Manual Name', $user->name);
        // Assert providers now include both
        $this->assertContains('local', $user->auth_providers);
        $this->assertContains('google', $user->auth_providers);
        // Assert firebase_uid is set
        $this->assertEquals('google_uid_123', $user->firebase_uid);
    }

    /**
     * Test Google login creates new user with 'google' provider
     */
    public function test_google_login_creates_new_user_with_google_provider()
    {
        $controller = new \App\Http\Controllers\AuthController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('createOrFindFirebaseUser');
        $method->setAccessible(true);

        $response = $method->invoke(
            $controller,
            'google_uid_456',
            'newuser@example.com',
            'New Google User',
            'https://example.com/picture.jpg'
        );

        $user = User::where('email', 'newuser@example.com')->first();

        $this->assertNotNull($user);
        $this->assertEquals(['google'], $user->auth_providers);
        $this->assertEquals('google_uid_456', $user->firebase_uid);
        $this->assertEquals('New Google User', $user->name);
    }

    /**
     * Test manual login adds 'local' provider if not exists
     */
    public function test_manual_login_adds_local_provider()
    {
        // Create user with only Google provider
        $user = User::create([
            'name' => 'Google User',
            'email' => 'hybrid@example.com',
            'phone' => '081234567891',
            'password' => Hash::make('password'),
            'firebase_uid' => 'google_uid_789',
            'auth_providers' => ['google'],
            'role_id' => 2,
            'verified_at' => now(),
        ]);

        // Login with password
        $response = $this->postJson('/api/auth/login', [
            'email' => 'hybrid@example.com',
            'password' => 'password',
            'scope' => 'user',
        ]);

        $response->assertStatus(200);

        // Reload user
        $user->refresh();

        // Assert both providers are present
        $this->assertContains('google', $user->auth_providers);
        $this->assertContains('local', $user->auth_providers);
    }

    /**
     * Test account endpoint returns auth_providers
     */
    public function test_account_endpoint_returns_auth_providers()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'account@example.com',
            'phone' => '081234567892',
            'password' => Hash::make('password'),
            'auth_providers' => ['local', 'google'],
            'role_id' => 2,
            'verified_at' => now(),
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/account');

        $response->assertStatus(200)
            ->assertJsonPath('data.profile.auth_providers', ['local', 'google']);
    }

    /**
     * Test existing users get default providers from migration
     */
    public function test_existing_users_have_default_providers()
    {
        // Create user with password (should get 'local')
        $localUser = User::create([
            'name' => 'Local User',
            'email' => 'local@example.com',
            'phone' => '081234567893',
            'password' => Hash::make('password'),
            'role_id' => 2,
        ]);

        // Create user with firebase_uid (should get 'google')
        $googleUser = User::create([
            'name' => 'Google User',
            'email' => 'google@example.com',
            'firebase_uid' => 'google_uid_999',
            'role_id' => 2,
        ]);

        // Run the migration logic
        DB::table('users')
            ->whereNull('auth_providers')
            ->whereNotNull('password')
            ->update(['auth_providers' => json_encode(['local'])]);

        DB::table('users')
            ->whereNull('auth_providers')
            ->whereNotNull('firebase_uid')
            ->whereNull('password')
            ->update(['auth_providers' => json_encode(['google'])]);

        // Reload users
        $localUser->refresh();
        $googleUser->refresh();

        $this->assertEquals(['local'], $localUser->auth_providers);
        $this->assertEquals(['google'], $googleUser->auth_providers);
    }
}

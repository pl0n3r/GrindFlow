<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_is_available(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_two_anonymous_login_gets_keep_csrf_and_allow_one_successful_post(): void
    {
        $user = User::factory()->create([
            'password' => 'correct-password',
        ]);

        $first = $this->get('/login')->assertOk();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $first->getContent(), $initialToken));

        $second = $this->get('/login')->assertOk();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $second->getContent(), $currentToken));
        self::assertSame($initialToken[1], $currentToken[1]);

        $this->post('/login', [
            '_token' => $currentToken[1],
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    /** A rejected synthetic login must not invalidate the anonymous session. */
    public function test_rejected_login_preserves_anonymous_csrf_and_allows_a_later_valid_login(): void
    {
        $user = User::factory()->create([
            'password' => 'correct-synthetic-password',
        ]);

        $before = $this->get('/login')->assertOk();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $before->getContent(), $beforeToken));

        $this->from('/login')->post('/login', [
            '_token' => $beforeToken[1],
            'email' => $user->email,
            'password' => 'incorrect-synthetic-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        $after = $this->get('/login')->assertOk();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $after->getContent(), $afterToken));
        self::assertSame($beforeToken[1], $afterToken[1],
            'A rejected login should not rotate the anonymous session CSRF token.');

        $this->post('/login', [
            '_token' => $afterToken[1],
            'email' => $user->email,
            'password' => 'correct-synthetic-password',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_authenticate_and_logout(): void
    {
        $user = User::factory()->create([
            'password' => 'correct-password',
        ]);

        $this->post('/login', [
            'email' => Str::upper($user->email),
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_invalid_credentials_do_not_authenticate(): void
    {
        $user = User::factory()->create([
            'password' => 'correct-password',
        ]);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** A valid login clears the same private limiter key used for failed attempts. */
    public function test_successful_login_clears_the_private_throttle_key(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        $ip = '203.0.113.11';
        $rawKey = Str::transliterate(Str::lower($user->email).'|'.$ip);
        $throttleKey = 'login:'.hash_hmac('sha256', $rawKey, (string) config('app.key'));

        RateLimiter::clear($throttleKey);

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from('/login')
            ->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertRedirect('/login')->assertSessionHasErrors('email');

        self::assertSame(1, RateLimiter::attempts($throttleKey));
        self::assertSame(0, RateLimiter::attempts($rawKey));

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', [
                'email' => Str::upper($user->email),
                'password' => 'correct-password',
            ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        self::assertSame(0, RateLimiter::attempts($throttleKey));
        self::assertSame(0, RateLimiter::attempts($rawKey));
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        Event::fake([Lockout::class]);

        $email = 'lockout@example.com';
        $ip = '203.0.113.10';
        $rawKey = Str::transliterate(Str::lower($email).'|'.$ip);
        $throttleKey = 'login:'.hash_hmac('sha256', $rawKey, (string) config('app.key'));

        self::assertStringNotContainsString($email, $throttleKey);
        self::assertStringNotContainsString($ip, $throttleKey);

        RateLimiter::clear($throttleKey);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->from('/login')
                ->post('/login', [
                    'email' => $email,
                    'password' => 'wrong-password',
                ])
                ->assertRedirect('/login')
                ->assertSessionHasErrors('email');
        }

        self::assertSame(5, RateLimiter::attempts($throttleKey));
        self::assertSame(0, RateLimiter::attempts($rawKey));
        Event::assertNotDispatched(Lockout::class);

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from('/login')
            ->post('/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        Event::assertDispatched(Lockout::class);
        $this->assertGuest();

        RateLimiter::clear($throttleKey);
    }
}

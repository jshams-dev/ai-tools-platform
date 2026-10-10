<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsAiCatalog;
use Tests\TestCase;

/**
 * Sign-in, sign-up and password reset: Fortify handles the requests, so
 * these check our pages and that each flow lands back in the workspace.
 */
final class AuthenticationTest extends TestCase
{
    use SeedsAiCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ToolCatalogSeeder::class);
        $this->withoutVite();
    }

    #[Test]
    public function the_sign_in_pages_render(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(__('Welcome back'));
        $this->get(route('register'))->assertOk()->assertSee(__('Create your account'));
        $this->get(route('password.request'))->assertOk()->assertSee(__('Send reset link'));
        $this->get(route('password.reset', 'some-token'))->assertOk()->assertSee(__('Choose a new password'));
    }

    #[Test]
    public function signing_up_creates_the_account_and_signs_it_in(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Sara',
            'email' => 'sara@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs(User::firstWhere('email', 'sara@example.com'));
    }

    #[Test]
    public function a_user_signs_in_and_sees_their_name_in_the_sidebar(): void
    {
        $user = User::factory()->create(['name' => 'Sara']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/');

        $this->get(route('home'))->assertSee('Sara')->assertSee(__('Sign out'));
    }

    #[Test]
    public function a_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'not-it',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function a_visitor_is_offered_sign_in_instead_of_a_profile(): void
    {
        $this->get(route('home'))
            ->assertSee(__('Sign in'))
            ->assertSee(__('Create a free account'))
            ->assertDontSee(__('Sign out'));
    }

    #[Test]
    public function signing_out_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }

    #[Test]
    public function a_reset_link_is_sent_to_the_account_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }
}

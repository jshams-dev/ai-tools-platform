<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\ToolCatalogSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsAiCatalog;
use Tests\TestCase;

/**
 * The catalog the Next.js frontend renders its pages from, and who is
 * signed in.
 */
final class ToolCatalogApiTest extends TestCase
{
    use SeedsAiCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ToolCatalogSeeder::class);
    }

    #[Test]
    public function the_catalog_lists_active_tools(): void
    {
        $this->getJson(route('api.v1.tools.index'))
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'text-transformer')
            ->assertJsonPath('data.0.category', 'writing');
    }

    #[Test]
    public function a_tool_describes_its_fields_starters_and_models(): void
    {
        $response = $this->getJson(route('api.v1.tools.show', 'text-transformer'))->assertOk();

        $response
            ->assertJsonPath('data.view', null)
            ->assertJsonPath('data.fields.0.name', 'text')
            ->assertJsonPath('data.fields.0.primary', true)
            ->assertJsonPath('data.fields.0.max_length', 8000)
            ->assertJsonPath('data.fields.1.name', 'mode')
            ->assertJsonPath('data.fields.1.options.0', ['value' => 'rewrite', 'label' => 'Rewrite'])
            ->assertJsonPath('data.default_model', 'groq-qwen3.8-27b')
            ->assertJsonPath('data.models.0.provider', 'Groq');

        $this->assertNotEmpty($response->json('data.starters'));
    }

    #[Test]
    public function guests_and_free_users_are_never_offered_pro_models(): void
    {
        $guestModels = collect($this->getJson(route('api.v1.tools.show', 'text-transformer'))->json('data.models'))->pluck('key');

        $this->actingAs(User::factory()->create());
        $userModels = collect($this->getJson(route('api.v1.tools.show', 'text-transformer'))->json('data.models'))->pluck('key');

        foreach ([$guestModels, $userModels] as $keys) {
            $this->assertNotEmpty($keys);
            $this->assertNotContains('claude-haiku', $keys);
        }
    }

    #[Test]
    public function a_model_never_leaks_its_price_or_vendor_id(): void
    {
        $model = $this->getJson(route('api.v1.tools.show', 'text-transformer'))->json('data.models.0');

        $this->assertSame(['key', 'name', 'provider'], array_keys($model));
    }

    #[Test]
    public function an_unknown_tool_is_a_json_404(): void
    {
        $this->getJson(route('api.v1.tools.show', 'does-not-exist'))
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    #[Test]
    public function the_signed_in_user_is_returned_and_a_guest_gets_401(): void
    {
        $this->getJson(route('api.v1.user'))->assertUnauthorized();

        $user = User::factory()->create(['name' => 'Sara']);

        $this->actingAs($user)
            ->getJson(route('api.v1.user'))
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'name' => 'Sara',
                'email' => $user->email,
                'plan' => 'free',
            ]]);
    }
}

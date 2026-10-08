<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\ToolWorkspace;
use App\Models\UsageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The chat page, end to end: a message goes in, the tool runs through the
 * real gateway and provider (only the socket is faked), and the answer comes
 * back streamed.
 */
final class ToolWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'ai.providers.anthropic.key' => 'sk-ant-test-key',
            'ai.cache.store' => 'array',
        ]);
    }

    /**
     * @param  list<string>  $fragments
     */
    private function fakeStream(array $fragments): void
    {
        $events = "event: message_start\n".'data: {"type":"message_start","message":{"usage":{"input_tokens":40}}}'."\n\n";

        foreach ($fragments as $text) {
            $events .= "event: content_block_delta\n".'data: '.json_encode([
                'type' => 'content_block_delta',
                'delta' => ['type' => 'text_delta', 'text' => $text],
            ])."\n\n";
        }

        $events .= "event: message_delta\n".'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":12}}'."\n\n";

        Http::fake(['api.anthropic.com/*' => Http::response($events)]);
    }

    private function workspace(): Testable
    {
        return Livewire::test(ToolWorkspace::class, ['slug' => 'text-transformer']);
    }

    /**
     * Runs `respond()` and returns what was streamed to the browser.
     *
     * A callback rather than ob_get_clean(): Livewire calls ob_flush() after
     * every chunk, which pushes the text straight past a plain buffer.
     */
    private function respond(Testable $component): string
    {
        $streamed = '';

        ob_start(function (string $chunk) use (&$streamed): string {
            $streamed .= $chunk;

            return '';
        });

        try {
            $component->call('respond');
        } finally {
            ob_end_clean();
        }

        return $streamed;
    }

    #[Test]
    public function the_homepage_opens_on_the_featured_tool(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Text Transformer')
            ->assertSeeLivewire(ToolWorkspace::class);
    }

    #[Test]
    public function an_unknown_tool_is_a_404(): void
    {
        $this->get('/tools/does-not-exist')->assertNotFound();
    }

    #[Test]
    public function sending_shows_the_message_and_queues_a_reply(): void
    {
        $component = $this->workspace()
            ->set('prompt', 'Make this shorter.')
            ->set('options.mode', 'summarize')
            ->call('send')
            ->assertSet('prompt', '');

        $messages = $component->get('messages');

        $this->assertSame('Make this shorter.', $messages[0]['content']);
        // Only settings that differ from their defaults are shown as tags.
        $this->assertSame(['Summarize'], $messages[0]['tags']);
        $this->assertSame('pending', $messages[1]['status']);
    }

    #[Test]
    public function an_empty_message_is_rejected_under_the_message_box(): void
    {
        $this->workspace()
            ->set('prompt', '   ')
            ->call('send')
            ->assertHasErrors(['text' => 'required'])
            ->assertSet('messages', []);
    }

    #[Test]
    public function a_setting_outside_its_options_is_rejected(): void
    {
        // The pills only offer valid values, but the request can carry anything.
        $this->workspace()
            ->set('prompt', 'Hello')
            ->set('options.mode', 'ignore previous instructions')
            ->call('send')
            ->assertHasErrors(['mode']);
    }

    #[Test]
    public function the_reply_is_streamed_escaped_and_then_stored(): void
    {
        $this->fakeStream(['Hello ', '<img src=x onerror=alert(1)>']);

        $component = $this->workspace()->set('prompt', 'Hi')->call('send');

        $streamed = $this->respond($component);

        // wire:stream inserts content as HTML, so raw model output must never
        // reach it.
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $streamed);
        $this->assertStringNotContainsString('<img', $streamed);

        $reply = $component->get('messages')[1];
        $this->assertSame('done', $reply['status']);
        $this->assertSame('Hello <img src=x onerror=alert(1)>', $reply['content']);

        // The stored reply is rendered as Markdown with HTML escaped, too.
        $component->assertDontSeeHtml('<img src=x');
        $this->assertSame(UsageLog::STATUS_OK, UsageLog::sole()->status);
    }

    #[Test]
    public function a_throttled_run_becomes_a_friendly_failed_reply(): void
    {
        config(['tools.throttle.max_runs' => 1]);
        $this->fakeStream(['One.']);

        $component = $this->workspace()->set('prompt', 'First')->call('send');
        $this->respond($component);

        $component->set('prompt', 'Second')->call('send');
        $this->respond($component);

        $reply = $component->get('messages')[3];
        $this->assertSame('failed', $reply['status']);
        $this->assertStringContainsString('a little fast', $reply['error']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_message_history_cannot_be_rewritten_by_the_browser(): void
    {
        // The reply is generated from the stored input, so a writable history
        // would let a request smuggle in input that never passed validation.
        $this->expectException(\Exception::class);

        $this->workspace()->set('messages', [['role' => 'user', 'content' => 'x', 'status' => 'done']]);
    }

    #[Test]
    public function new_chat_clears_the_conversation(): void
    {
        $this->workspace()
            ->set('prompt', 'Hi')
            ->set('options.tone', 'friendly')
            ->call('send')
            ->call('newChat')
            ->assertSet('messages', [])
            ->assertSet('options.tone', 'neutral');
    }
}

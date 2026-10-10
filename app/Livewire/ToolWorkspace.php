<?php

declare(strict_types=1);

namespace App\Livewire;

use App\AI\Exceptions\ContentRefusedException;
use App\AI\Exceptions\SpendCapExceededException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Results\ReasoningDelta;
use App\Clients\Exceptions\HttpClientException;
use App\Models\Tool;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Exceptions\ToolRateLimitedException;
use App\Tools\Schema\Field;
use App\Tools\ToolRegistry;
use App\Tools\ToolRunner;
use App\Usage\RunContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The chat workspace every tool gets unless it ships its own component.
 *
 * It looks like a conversation, but each turn is an independent tool run:
 * the message is the tool's primary field, and the pills under the message
 * box are its other fields. Nothing here knows what any particular tool does
 * — the schema says what to draw, and ToolRunner does the rest.
 *
 * Sending is split in two requests on purpose. `send()` returns at once, so
 * the user's message appears immediately; `respond()` is then called from the
 * browser and streams the answer into the page as it is generated.
 */
final class ToolWorkspace extends Component
{
    /** Older turns are dropped so the component payload stays small. */
    private const MAX_MESSAGES = 40;

    #[Locked]
    public string $slug;

    public string $prompt = '';

    /** @var array<string, string|null> Non-primary field values, by field name. */
    public array $options = [];

    /** The caller's model choice. Untrusted: the resolver checks it against the policy and tier. */
    public ?string $model = null;

    /**
     * Locked, so the browser cannot rewrite the history it sends back — the
     * server owns every message, including the input a reply is generated from.
     *
     * @var list<array{id: string, role: 'user'|'assistant', content: string, status: 'done'|'pending'|'failed', input?: array<string, string|null>, tags?: list<string>, error?: string}>
     */
    #[Locked]
    public array $messages = [];

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $this->options = $this->defaultOptions();
    }

    /** The tool's row: name, description, and the models it may use. */
    #[Computed]
    public function tool(): Tool
    {
        return app(ToolRegistry::class)->get($this->slug);
    }

    /** The tool's code: its fields and handler. */
    #[Computed]
    public function definition(): ToolInterface
    {
        return app(ToolRegistry::class)->definitionFor($this->tool);
    }

    public function send(ToolRunner $runner): void
    {
        if ($this->isResponding()) {
            return;
        }

        $primary = $this->primaryField();

        // Validated here as well as in respond(): a bad input should show up
        // under the message box, not as a failed reply in the conversation.
        $input = $runner->validate($this->definition, [...$this->options, $primary->name => $this->prompt]);

        $this->messages[] = [
            'id' => Str::random(12),
            'role' => 'user',
            'content' => (string) $input[$primary->name],
            'status' => 'done',
            'input' => $input,
            'tags' => $this->tagsFor($input),
        ];

        $this->messages[] = [
            'id' => Str::random(12),
            'role' => 'assistant',
            'content' => '',
            'status' => 'pending',
        ];

        $this->messages = array_slice($this->messages, -self::MAX_MESSAGES);
        $this->prompt = '';

        $this->js('$wire.respond()');
    }

    public function respond(ToolRunner $runner): void
    {
        $index = array_key_last($this->messages);

        if ($index === null || $index === 0 || $this->messages[$index]['status'] !== 'pending') {
            return;
        }

        $reply = $this->messages[$index];
        $question = $this->messages[$index - 1];

        // A slow model can stream for well over PHP's default 30 seconds. If
        // PHP kills the request mid-stream, nothing after the kill runs — no
        // error message, no usage row — and the browser gets a broken
        // response. The HTTP client's own stream timeout is the real limit;
        // this just keeps PHP from cutting in first.
        set_time_limit((int) config('ai.http.stream_timeout', 300) + 30);

        $ctx = RunContext::forGuest(
            toolSlug: $this->tool->slug,
            policy: $this->tool->modelPolicy(),
            ip: (string) request()->ip(),
            requestedModel: $this->model,
        );

        $buffer = '';
        $thought = '';
        $statusSentAt = 0.0;

        try {
            foreach ($runner->stream($this->definition, $question['input'] ?? [], $ctx) as $fragment) {
                if ($fragment instanceof ReasoningDelta) {
                    $thought .= $fragment->text;

                    // Thinking arrives a few characters at a time; a status
                    // line only needs refreshing a few times a second.
                    if (microtime(true) - $statusSentAt >= 0.25) {
                        $this->stream(e(self::thoughtPreview($thought)), replace: true, name: 'status-'.$reply['id']);
                        $statusSentAt = microtime(true);
                    }

                    continue;
                }

                $buffer .= $fragment;

                // wire:stream inserts what it receives as HTML. Model output
                // is untrusted text, so each fragment is escaped on the way
                // out — otherwise a reply containing <img onerror=…> runs.
                $this->stream(e($fragment), name: 'reply-'.$reply['id']);
            }

            $this->messages[$index] = [...$reply, 'content' => $buffer, 'status' => 'done'];
        } catch (\Throwable $e) {
            // Whatever arrived before the failure is kept: the user has
            // already read it, and it disappearing would be stranger.
            $this->messages[$index] = [...$reply, 'content' => $buffer, 'status' => 'failed', 'error' => $this->explain($e)];
        }
    }

    /**
     * One line saying what the model is doing right now.
     *
     * Gemini writes its thoughts as short titled steps ("**Checking divisors**
     * …"), so the latest title is the best summary. Other models think in
     * plain prose, so the tail of it is shown instead.
     */
    private static function thoughtPreview(string $thought): string
    {
        if (preg_match_all('/\*\*(.+?)\*\*/u', $thought, $titles) > 0) {
            return trim((string) end($titles[1]));
        }

        $flat = trim((string) preg_replace('/\s+/u', ' ', $thought));

        if (mb_strlen($flat) <= 110) {
            return $flat;
        }

        $tail = mb_substr($flat, -110);

        // Start at a word, not mid-word.
        return '…'.ltrim((string) preg_replace('/^\S*\s/u', '', $tail));
    }

    /**
     * Called by the browser when the respond() request itself failed — the
     * connection dropped or the server died mid-reply — so no reply state
     * ever came back. Without this the reply would stay "thinking" forever
     * and block the message box.
     */
    public function abandonReply(): void
    {
        $index = array_key_last($this->messages);

        if ($index === null || $this->messages[$index]['status'] !== 'pending') {
            return;
        }

        $this->messages[$index] = [
            ...$this->messages[$index],
            'status' => 'failed',
            'error' => __('The connection was interrupted before the reply finished. Please try again.'),
        ];
    }

    /**
     * Put a sent message back in the message box, with the settings it was
     * sent with, so it can be changed and sent again.
     */
    public function editMessage(string $id): void
    {
        foreach ($this->messages as $message) {
            if ($message['id'] === $id && $message['role'] === 'user') {
                $primary = $this->primaryField()->name;
                $input = $message['input'] ?? [];

                $this->prompt = (string) ($input[$primary] ?? $message['content']);
                $this->options = [...$this->defaultOptions(), ...array_intersect_key($input, $this->defaultOptions())];
                $this->dispatch('focus-composer');

                return;
            }
        }
    }

    public function useStarter(int $index): void
    {
        $starter = $this->definition->starters()[$index] ?? null;

        if ($starter === null) {
            return;
        }

        $this->options = [...$this->defaultOptions(), ...($starter['options'] ?? [])];

        if (isset($starter['prompt'])) {
            $this->prompt = $starter['prompt'];
        }

        $this->dispatch('focus-composer');
    }

    #[On('new-chat')]
    public function newChat(): void
    {
        $this->reset('prompt', 'messages');
        $this->options = $this->defaultOptions();
        $this->resetErrorBag();
        $this->dispatch('focus-composer');
    }

    public function render(): View
    {
        $models = $this->tool->modelsFor(RunContext::TIER_GUEST);

        return view('livewire.tool-workspace', [
            'tool' => $this->tool,
            'starters' => $this->definition->starters(),
            'primaryField' => $this->primaryField(),
            'settingFields' => $this->settingFields(),
            'models' => $models,
            'defaultModel' => $models->first(),
        ]);
    }

    private function primaryField(): Field
    {
        foreach ($this->definition->inputSchema() as $field) {
            if ($field->isPrimary()) {
                return $field;
            }
        }

        throw new \LogicException("Tool '{$this->slug}' has no primary field, so the chat has nothing to type into.");
    }

    /**
     * @return list<Field>
     */
    private function settingFields(): array
    {
        return array_values(array_filter(
            $this->definition->inputSchema(),
            fn (Field $field): bool => ! $field->isPrimary() && $field->type === Field::TYPE_SELECT,
        ));
    }

    /**
     * @return array<string, string|null>
     */
    private function defaultOptions(): array
    {
        $defaults = [];

        foreach ($this->settingFields() as $field) {
            $defaults[$field->name] = $field->getDefault();
        }

        return $defaults;
    }

    /**
     * Labels for the settings that differ from their defaults, shown under
     * the user's message so each turn records how it was asked.
     *
     * @param  array<string, string|null>  $input
     * @return list<string>
     */
    private function tagsFor(array $input): array
    {
        $tags = [];

        foreach ($this->settingFields() as $field) {
            $value = $input[$field->name] ?? null;

            if ($value !== null && $value !== $field->getDefault()) {
                $tags[] = $field->labelFor($value);
            }
        }

        return $tags;
    }

    private function isResponding(): bool
    {
        $last = end($this->messages);

        return $last !== false && $last['status'] === 'pending';
    }

    /**
     * A message for the person, not the log. Anything unexpected is still
     * reported, so a friendly sentence never hides a real bug.
     */
    private function explain(\Throwable $e): string
    {
        return match (true) {
            $e instanceof ToolRateLimitedException => __("You're sending requests a little fast. Try again in :seconds seconds.", ['seconds' => $e->retryAfterSeconds]),
            $e instanceof SpendCapExceededException => __('The platform has reached its usage limit for today. Please try again tomorrow.'),
            $e instanceof ContentRefusedException => __('The model declined to answer this request.'),
            $e instanceof HttpClientException, $e instanceof UnsupportedCapabilityException => $this->reportAndSay($e, __('The AI service is unavailable right now. Please try again in a moment.')),
            default => $this->reportAndSay($e, __('Something went wrong. Please try again.')),
        };
    }

    private function reportAndSay(\Throwable $e, string $message): string
    {
        report($e);

        return $message;
    }
}

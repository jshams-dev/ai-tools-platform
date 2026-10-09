/**
 * Small Alpine behaviours for the chat workspace. Livewire ships Alpine and
 * fires `alpine:init` before it starts, so registering here is enough.
 */
/**
 * Copy text, also on plain-HTTP pages.
 *
 * navigator.clipboard only exists on secure origins (HTTPS or localhost), so
 * on a LAN address like http://192.168.1.10 it is undefined. The fallback
 * selects a hidden textarea and uses the older copy command, which still
 * works everywhere.
 */
async function copyText(text) {
    if (window.isSecureContext && navigator.clipboard) {
        await navigator.clipboard.writeText(text);

        return true;
    }

    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();

    try {
        return document.execCommand('copy');
    } finally {
        area.remove();
    }
}

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /** `$copy(text)` in any Alpine expression. */
    Alpine.magic('copy', () => copyText);

    /**
     * The workspace root. If the request that streams a reply fails outright
     * — the connection drops, or the server dies mid-reply — Livewire's
     * default is an error dialog that ends in a page reload, which throws
     * the whole conversation away. Instead, the reply is marked as failed
     * and the conversation stays.
     */
    Alpine.data('workspace', () => ({
        init() {
            this.$wire.$interceptRequest('respond', ({ onError, onFailure }) => {
                onError(({ preventDefault }) => {
                    preventDefault();
                    this.$wire.abandonReply();
                });

                onFailure(() => this.$wire.abandonReply());
            });
        },
    }));

    /** The message box: grows with its content, Enter sends, Shift+Enter breaks a line. */
    Alpine.data('composer', (max = null) => ({
        max,

        get count() {
            return (this.$wire.prompt ?? '').length;
        },

        get isEmpty() {
            return (this.$wire.prompt ?? '').trim() === '';
        },

        get nearLimit() {
            return this.max !== null && this.count > this.max * 0.8;
        },

        init() {
            this.$nextTick(() => this.resize());

            // The server clears the box after sending; shrink it back too.
            this.$watch('$wire.prompt', () => this.$nextTick(() => this.resize()));
        },

        resize() {
            const input = this.$refs.input;
            input.style.height = 'auto';
            input.style.height = `${input.scrollHeight}px`;
        },

        focus() {
            this.$nextTick(() => {
                this.resize();
                this.$refs.input.focus();
            });
        },

        submit() {
            if (! this.isEmpty) {
                this.$wire.send();
            }
        },
    }));

    /**
     * The conversation: follows new output to the bottom while the reader is
     * there, and stops following as soon as they scroll up to read.
     */
    Alpine.data('thread', () => ({
        following: true,

        init() {
            this.toBottom();

            new MutationObserver(() => {
                if (this.following) {
                    this.toBottom();
                }
            }).observe(this.$el, { childList: true, subtree: true, characterData: true });
        },

        track() {
            const el = this.$el;
            this.following = el.scrollHeight - el.scrollTop - el.clientHeight < 96;
        },

        toBottom() {
            this.$el.scrollTop = this.$el.scrollHeight;
        },
    }));
});

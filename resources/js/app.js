/**
 * Small Alpine behaviours for the chat workspace. Livewire ships Alpine and
 * fires `alpine:init` before it starts, so registering here is enough.
 */
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

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

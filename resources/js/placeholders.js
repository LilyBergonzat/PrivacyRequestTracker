const PLACEHOLDER = /\{\{\s*([a-z0-9_]+)\s*\}\}/gi;

const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

function escapeHtml(value) {
    return value.replace(/[&<>"']/g, (character) => HTML_ESCAPES[character]);
}

export function registerPlaceholders(Alpine) {
    Alpine.store('placeholders', {
        values: {},

        reset() {
            this.values = {};
        },
    });

    document.addEventListener('livewire:navigated', () => Alpine.store('placeholders').reset());

    Alpine.data('placeholderFill', () => ({
        template: '',

        init() {
            this.template = this.$el.innerHTML;

            Alpine.effect(() => {
                const values = this.$store.placeholders.values;

                this.$el.innerHTML = this.template.replace(PLACEHOLDER, (placeholder, name) => {
                    const value = (values[name.toLowerCase()] ?? '').trim();

                    return value === '' ? placeholder : escapeHtml(value);
                });

                this.addCopyButtons();
            });
        },

        addCopyButtons() {
            const copyLabel = this.$el.dataset.copyLabel ?? 'Copy';
            const copiedLabel = this.$el.dataset.copiedLabel ?? 'Copied!';

            this.$el.querySelectorAll('pre').forEach((pre) => {
                const button = document.createElement('button');

                button.type = 'button';
                button.textContent = copyLabel;
                button.className = 'absolute end-2 top-2 rounded-md bg-zinc-700/80 px-2 py-0.5 text-xs font-medium text-white hover:bg-zinc-600';
                button.addEventListener('click', async () => {
                    await navigator.clipboard.writeText((pre.querySelector('code') ?? pre).textContent);

                    button.textContent = copiedLabel;
                    setTimeout(() => (button.textContent = copyLabel), 1500);
                });

                pre.classList.add('relative');
                pre.append(button);
            });
        },
    }));
}

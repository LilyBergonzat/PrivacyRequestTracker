/**
 * Alpine components used by the Blade templates.
 *
 * The app runs Livewire's CSP-safe Alpine build (no eval), which only understands simple
 * expressions in `x-…` attributes. Anything with logic lives here as a registered component,
 * and the templates only reference properties and method names.
 */
export function registerComponents(Alpine) {
    Alpine.data('twoFactorChallenge', () => ({
        showRecoveryInput: false,
        code: '',
        recovery_code: '',

        init() {
            this.showRecoveryInput = this.$el.dataset.showRecovery === 'true';

            if (!this.showRecoveryInput) {
                this.focusOtp();
            }
        },

        focusOtp() {
            this.$nextTick(() => this.$refs.otp?.querySelector('input')?.focus());
        },

        toggleInput() {
            this.showRecoveryInput = !this.showRecoveryInput;
            this.code = '';
            this.recovery_code = '';

            this.$nextTick(() => {
                if (this.showRecoveryInput) {
                    this.$refs.recovery_code?.focus();
                } else {
                    this.focusOtp();
                }
            });
        },
    }));

    Alpine.data('copyToClipboard', () => ({
        copied: false,

        async copy() {
            try {
                await navigator.clipboard.writeText(this.$el.closest('[data-copy-value]').dataset.copyValue);
                this.copied = true;
                setTimeout(() => (this.copied = false), 1500);
            } catch {
                console.warn('Could not copy to clipboard');
            }
        },
    }));

    Alpine.data('recoveryCodes', () => ({
        showRecoveryCodes: false,

        show() {
            this.showRecoveryCodes = true;
        },

        hide() {
            this.showRecoveryCodes = false;
        },
    }));

    Alpine.data('focusFirstInput', () => ({
        init() {
            this.$nextTick(() => this.$el.querySelector('input')?.focus());
        },
    }));

    Alpine.data('duplicateConfirmation', () => ({
        confirmDuplicates() {
            this.$wire.duplicatesConfirmed = true;
        },
    }));
}

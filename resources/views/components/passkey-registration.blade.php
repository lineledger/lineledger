@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,

        // Password confirmation step-up (Fortify).
        confirming: false,
        password: '',
        passwordError: null,
        passwordLoading: false,

        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        init() {
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },

        // Mirror @laravel/passkeys: prefer the csrf-token meta tag, fall back to
        // the XSRF-TOKEN cookie Laravel sets on every session.
        csrfHeader() {
            const meta = document.querySelector('meta[name=csrf-token]')?.getAttribute('content');
            if (meta) {
                return { 'X-CSRF-TOKEN': meta };
            }

            const prefix = 'XSRF-TOKEN=';
            const cookie = document.cookie.split('; ').find((c) => c.startsWith(prefix));

            return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(prefix.length)) } : {};
        },

        async register() {
            if (!this.name.trim()) return;

            this.error = null;

            // The passkey routes are gated by Fortify's `password.confirm`. Confirm
            // first (in-page) so the registration request isn't rejected with a 423.
            const confirmed = await this.isPasswordConfirmed();
            if (confirmed === null) return;

            if (!confirmed) {
                this.openPasswordPrompt();
                return;
            }

            await this.doRegister();
        },

        async isPasswordConfirmed() {
            try {
                const response = await fetch('{{ route('password.confirmation') }}', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) throw new Error();

                const data = await response.json();

                return Boolean(data.confirmed);
            } catch {
                this.error = '{{ __('Could not verify your session. Please try again.') }}';

                return null;
            }
        },

        openPasswordPrompt() {
            this.password = '';
            this.passwordError = null;
            this.confirming = true;
        },

        closePasswordPrompt() {
            this.confirming = false;
            this.password = '';
            this.passwordError = null;
        },

        async confirmPassword() {
            if (!this.password || this.passwordLoading) return;

            this.passwordLoading = true;
            this.passwordError = null;

            try {
                const response = await fetch('{{ route('password.confirm.store') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        ...this.csrfHeader(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ password: this.password }),
                });

                if (response.status === 422) {
                    const data = await response.json().catch(() => ({}));
                    this.passwordError = data.errors?.password?.[0]
                        ?? data.message
                        ?? '{{ __('The provided password was incorrect.') }}';

                    return;
                }

                if (!response.ok) {
                    this.passwordError = '{{ __('Could not confirm your password. Please try again.') }}';

                    return;
                }

                this.closePasswordPrompt();
                await this.doRegister();
            } catch {
                this.passwordError = '{{ __('Could not confirm your password. Please try again.') }}';
            } finally {
                this.passwordLoading = false;
            }
        },

        async doRegister() {
            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await $wire.loadPasskeys();
            } catch (e) {
                if (e.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },

        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }"
>
    <template x-if="!supported">
        <flux:text>{{ __('Passkeys are not supported in this browser.') }}</flux:text>
    </template>

    <template x-if="supported && !showForm">
        <div>
            <flux:button
                variant="primary"
                icon="plus"
                x-on:click="showForm = true"
            >
                {{ __('Add passkey') }}
            </flux:button>
        </div>
    </template>

    <template x-if="supported && showForm">
        <div class="space-y-4 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 p-4">
            <flux:input
                label="{{ __('Passkey name') }}"
                x-model="name"
                placeholder="{{ __('e.g., MacBook Pro, iPhone') }}"
                x-on:keydown.enter.prevent="register()"
                x-ref="passkeyNameInput"
                x-init="$nextTick(() => $refs.passkeyNameInput?.focus())"
            />
            <flux:text class="!mt-1">{{ __('Give this passkey a name to help you identify it later.') }}</flux:text>

            <p x-show="error" x-text="error" x-cloak class="text-sm text-red-600 dark:text-red-400"></p>

            <div class="flex gap-2">
                <flux:button
                    variant="primary"
                    x-on:click="register()"
                    x-bind:disabled="loading || !name.trim()"
                >
                    <span x-show="!loading">{{ __('Register passkey') }}</span>
                    <span x-show="loading" x-cloak>{{ __('Registering...') }}</span>
                </flux:button>
                <flux:button
                    variant="ghost"
                    x-on:click="cancel()"
                >
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </div>
    </template>

    {{-- Password confirmation modal (step-up before touching the guarded passkey routes). --}}
    <div
        x-show="confirming"
        x-cloak
        x-effect="confirming && $nextTick(() => $refs.confirmPasswordInput?.focus())"
        @keydown.escape.window="!passwordLoading && closePasswordPrompt()"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        x-transition.opacity
    >
        <div
            class="absolute inset-0 bg-black/50"
            @click="!passwordLoading && closePasswordPrompt()"
        ></div>

        <div
            class="relative w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 shadow-xl"
            @keydown.enter.prevent="confirmPassword()"
        >
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Confirm your password') }}</flux:heading>
                <flux:text>{{ __('For your security, please confirm your password before registering a passkey.') }}</flux:text>
            </div>

            <div class="mt-6 space-y-2">
                <flux:input
                    type="password"
                    x-model="password"
                    x-ref="confirmPasswordInput"
                    label="{{ __('Password') }}"
                    autocomplete="current-password"
                    viewable
                />
                <p x-show="passwordError" x-text="passwordError" x-cloak class="text-sm text-red-600 dark:text-red-400"></p>
            </div>

            <div class="mt-6 flex gap-3 justify-end">
                <flux:button
                    variant="ghost"
                    x-on:click="closePasswordPrompt()"
                    x-bind:disabled="passwordLoading"
                >
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    variant="primary"
                    x-on:click="confirmPassword()"
                    x-bind:disabled="passwordLoading || !password"
                >
                    <span x-show="!passwordLoading">{{ __('Confirm') }}</span>
                    <span x-show="passwordLoading" x-cloak>{{ __('Confirming...') }}</span>
                </flux:button>
            </div>
        </div>
    </div>
</div>

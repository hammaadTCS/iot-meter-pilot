<section>
    <header class="mb-6">
        <h2 class="font-mono text-sm font-bold text-iot-red uppercase tracking-widest">Delete Account</h2>
        <p class="mt-1 text-sm text-iot-muted">
            Once deleted, all your data will be permanently removed. Download anything you want to keep first.
        </p>
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >Delete My Account</x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <div class="w-12 h-12 rounded-full bg-red-500/10 border border-red-500/20
                        flex items-center justify-center mx-auto mb-4">
                <svg class="w-6 h-6 text-iot-red" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>

            <h2 class="font-mono text-base font-bold text-white text-center mb-2">
                Delete your account?
            </h2>
            <p class="text-sm text-iot-muted text-center mb-6">
                This action cannot be undone. Confirm with your password.
            </p>

            <div class="mb-4">
                <x-input-label for="password" value="Password" class="sr-only" />
                <x-text-input id="password" name="password" type="password" class="block w-full"
                    placeholder="Enter your password to confirm" />
                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-1" />
            </div>

            <div class="flex gap-3">
                <button type="button" x-on:click="$dispatch('close')"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-medium
                               bg-iot-surface2 text-iot-text border border-iot-border
                               hover:bg-iot-border transition-colors">
                    Cancel
                </button>
                <x-danger-button class="flex-1 justify-center">
                    Delete Account
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>

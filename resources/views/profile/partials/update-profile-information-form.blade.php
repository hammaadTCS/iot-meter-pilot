<section>
    <header class="mb-6">
        <h2 class="font-mono text-sm font-bold text-white uppercase tracking-widest">Profile Information</h2>
        <p class="mt-1 text-sm text-iot-muted">Update your account's profile information and email address.</p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="space-y-5">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-1" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-1" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="mt-2">
                    <p class="text-sm text-iot-amber">
                        {{ __('Your email address is unverified.') }}
                        <button form="send-verification"
                                class="underline text-iot-accent hover:text-iot-accent/80 focus:outline-none transition-colors">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>
                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 text-sm text-iot-green">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        {{-- CNIC --}}
        <div>
            <x-input-label for="cnic" value="CNIC" />
            <x-text-input id="cnic" name="cnic" type="text" class="block w-full font-mono"
                :value="old('cnic', $user->cnic)" maxlength="13" placeholder="1234512345671" />
            <p class="text-iot-muted text-xs mt-1">13-digit National ID number</p>
            <x-input-error class="mt-1" :messages="$errors->get('cnic')" />
        </div>

        {{-- Phone --}}
        <div>
            <x-input-label for="phone_number" value="Phone Number" />
            <x-text-input id="phone_number" name="phone_number" type="text" class="block w-full font-mono"
                :value="old('phone_number', $user->phone_number)" maxlength="11" placeholder="03001234567" />
            <p class="text-iot-muted text-xs mt-1">11-digit mobile number</p>
            <x-input-error class="mt-1" :messages="$errors->get('phone_number')" />
        </div>

        {{-- Address --}}
        <div>
            <x-input-label for="address" value="Address" />
            <textarea id="address" name="address" rows="3"
                class="block w-full bg-iot-surface2 border border-iot-border text-iot-text placeholder:text-iot-muted
                       rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                       resize-none"
                placeholder="Your full address">{{ old('address', $user->address) }}</textarea>
            <x-input-error class="mt-1" :messages="$errors->get('address')" />
        </div>

        <div class="flex items-center gap-4 pt-2">
            <x-primary-button>Save Changes</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p x-data="{ show: true }"
                   x-show="show"
                   x-transition
                   x-init="setTimeout(() => show = false, 2500)"
                   class="text-sm text-iot-green">Saved.</p>
            @endif
        </div>
    </form>
</section>

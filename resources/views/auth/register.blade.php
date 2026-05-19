<x-guest-layout>
    <h1 class="font-mono text-xl font-bold text-white mb-1">Create account</h1>
    <p class="text-sm text-iot-muted mb-6">Join the SmartHome IoT Platform</p>

    <form method="POST" action="{{ route('register') }}"
          x-data="{
              password: '',
              get strength() {
                  let s = 0;
                  if (this.password.length >= 8) s++;
                  if (/\d/.test(this.password)) s++;
                  if (/[^a-zA-Z0-9]/.test(this.password)) s++;
                  return s;
              }
          }"
          class="space-y-5">
        @csrf

        {{-- Section 1: Account --}}
        <div class="space-y-4">
            <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted border-b border-iot-border pb-2">
                Account Information
            </p>

            <div>
                <x-input-label for="name" value="Full Name" />
                <x-text-input id="name" class="block w-full" type="text" name="name"
                    :value="old('name')" required autofocus autocomplete="name"
                    placeholder="Your full name" />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="email" value="Email" />
                <x-text-input id="email" class="block w-full" type="email" name="email"
                    :value="old('email')" required autocomplete="username"
                    placeholder="you@example.com" />
                <x-input-error :messages="$errors->get('email')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="password" value="Password" />
                <x-text-input id="password" class="block w-full" type="password" name="password"
                    required autocomplete="new-password" placeholder="At least 8 characters"
                    x-model="password" />
                {{-- Strength bars --}}
                <div class="flex gap-1.5 mt-2" x-show="password.length > 0">
                    <div :class="strength >= 1 ? (strength === 1 ? 'bg-iot-red' : (strength === 2 ? 'bg-iot-amber' : 'bg-iot-green')) : 'bg-iot-surface2'"
                         class="flex-1 h-1 rounded-full transition-colors"></div>
                    <div :class="strength >= 2 ? (strength === 2 ? 'bg-iot-amber' : 'bg-iot-green') : 'bg-iot-surface2'"
                         class="flex-1 h-1 rounded-full transition-colors"></div>
                    <div :class="strength >= 3 ? 'bg-iot-green' : 'bg-iot-surface2'"
                         class="flex-1 h-1 rounded-full transition-colors"></div>
                </div>
                <p class="text-iot-muted text-xs mt-1" x-show="password.length > 0"
                   :class="strength === 3 ? 'text-iot-green' : (strength === 2 ? 'text-iot-amber' : 'text-iot-red')"
                   x-text="strength === 3 ? 'Strong' : (strength === 2 ? 'Medium — add a symbol' : 'Weak — use 8+ chars, numbers, symbols')">
                </p>
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="password_confirmation" value="Confirm Password" />
                <x-text-input id="password_confirmation" class="block w-full" type="password"
                    name="password_confirmation" required autocomplete="new-password"
                    placeholder="Repeat your password" />
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1" />
            </div>
        </div>

        {{-- Section 2: Profile --}}
        <div class="space-y-4">
            <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted border-b border-iot-border pb-2">
                Profile Details <span class="normal-case tracking-normal font-normal">(optional)</span>
            </p>

            <div>
                <x-input-label for="cnic" value="CNIC" />
                <x-text-input id="cnic" class="block w-full font-mono" type="text" name="cnic"
                    :value="old('cnic')" maxlength="13" placeholder="1234512345671" />
                <p class="text-iot-muted text-xs mt-1">13-digit National ID, no dashes</p>
                <x-input-error :messages="$errors->get('cnic')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="phone_number" value="Phone Number" />
                <x-text-input id="phone_number" class="block w-full font-mono" type="text" name="phone_number"
                    :value="old('phone_number')" maxlength="11" placeholder="03001234567" />
                <p class="text-iot-muted text-xs mt-1">11-digit number, no country code</p>
                <x-input-error :messages="$errors->get('phone_number')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="address" value="Address" />
                <textarea id="address" name="address" rows="2"
                    class="block w-full bg-iot-surface2 border border-iot-border text-iot-text placeholder:text-iot-muted
                           rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50 resize-none"
                    placeholder="Your full address">{{ old('address') }}</textarea>
                <x-input-error :messages="$errors->get('address')" class="mt-1" />
            </div>
        </div>

        <x-primary-button class="w-full justify-center">
            Create Account
        </x-primary-button>

        <p class="text-center text-sm text-iot-muted">
            Already have an account?
            <a href="{{ route('login') }}" class="text-iot-accent hover:text-iot-accent/80 transition-colors">
                Sign in
            </a>
        </p>
    </form>
</x-guest-layout>

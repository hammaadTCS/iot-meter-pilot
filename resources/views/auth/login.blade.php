<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <h1 class="font-mono text-xl font-bold text-white mb-1">Welcome back</h1>
    <p class="text-sm text-iot-muted mb-6">Sign in to your SmartHome account</p>

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input id="email" class="block w-full" type="email" name="email"
                :value="old('email')" required autofocus autocomplete="username"
                placeholder="you@example.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-1" />
        </div>

        <div>
            <x-input-label for="password" value="Password" />
            <x-text-input id="password" class="block w-full" type="password" name="password"
                required autocomplete="current-password" placeholder="••••••••" />
            <x-input-error :messages="$errors->get('password')" class="mt-1" />
        </div>

        <div class="flex items-center justify-between">
            <label for="remember_me" class="inline-flex items-center gap-2 cursor-pointer">
                <input id="remember_me" type="checkbox" name="remember"
                       class="w-4 h-4 rounded border-iot-border bg-iot-surface2 text-iot-accent
                              focus:ring-iot-accent/50 focus:ring-1">
                <span class="text-sm text-iot-muted">Remember me</span>
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}"
                   class="text-sm text-iot-accent hover:text-iot-accent/80 transition-colors">
                    Forgot password?
                </a>
            @endif
        </div>

        <x-primary-button class="w-full justify-center">
            Sign In
        </x-primary-button>

        @if (Route::has('register'))
            <p class="text-center text-sm text-iot-muted">
                No account?
                <a href="{{ route('register') }}" class="text-iot-accent hover:text-iot-accent/80 transition-colors">
                    Create one
                </a>
            </p>
        @endif
    </form>
</x-guest-layout>

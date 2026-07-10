<x-app-layout>
    <x-page-header title="Add User" subtitle="Create a new user account." />

    <div class="max-w-2xl">
        <div class="bg-iot-surface border border-iot-border rounded-2xl p-6 sm:p-8">
            <form action="{{ route('users.store') }}" method="POST" class="space-y-5">
                @csrf

                <div class="space-y-4">
                    <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted border-b border-iot-border pb-2">Account</p>

                    <div>
                        <x-input-label for="name" value="Full Name" />
                        <x-text-input id="name" name="name" type="text" class="block w-full"
                            :value="old('name')" required autofocus placeholder="Full name" />
                        <x-input-error :messages="$errors->get('name')" />
                    </div>

                    <div>
                        <x-input-label for="email" value="Email" />
                        <x-text-input id="email" name="email" type="email" class="block w-full"
                            :value="old('email')" required placeholder="user@example.com" />
                        <x-input-error :messages="$errors->get('email')" />
                    </div>

                    <div>
                        <x-input-label for="password" value="Password" />
                        <x-text-input id="password" name="password" type="password" class="block w-full"
                            required placeholder="Min. 8 characters" />
                        <x-input-error :messages="$errors->get('password')" />
                    </div>

                    <div>
                        <x-input-label for="password_confirmation" value="Confirm Password" />
                        <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="block w-full"
                            required placeholder="Repeat password" />
                    </div>

                    <div>
                        <x-input-label for="bundle" value="Access Bundle" />
                        <select id="bundle" name="bundle"
                            class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm
                                   focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                                   @error('bundle') border-iot-red @enderror">
                            <option value="consumer"       {{ old('bundle', 'consumer') === 'consumer'   ? 'selected' : '' }}>Consumer — own-meter dashboard, rename only</option>
                            <option value="prosumer"       {{ old('bundle') === 'prosumer'       ? 'selected' : '' }}>Prosumer — consumer + self-provision &amp; edit own meters</option>
                            <option value="field_engineer" {{ old('bundle') === 'field_engineer' ? 'selected' : '' }}>Field Engineer — create/edit/assign any device</option>
                            <option value="fleet_operator" {{ old('bundle') === 'fleet_operator' ? 'selected' : '' }}>Fleet Operator — fleet visibility, stats &amp; alerts</option>
                        </select>
                        <p class="text-xs text-iot-muted mt-1">Fine-tune individual permissions afterwards from the user's Manage Access screen.</p>
                        <x-input-error :messages="$errors->get('bundle')" />
                    </div>
                </div>

                <div class="space-y-4">
                    <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted border-b border-iot-border pb-2">
                        Profile <span class="normal-case tracking-normal font-normal">(optional)</span>
                    </p>

                    <div>
                        <x-input-label for="cnic" value="CNIC" />
                        <x-text-input id="cnic" name="cnic" type="text" class="block w-full font-mono"
                            :value="old('cnic')" maxlength="13" placeholder="1234512345671" />
                        <x-input-error :messages="$errors->get('cnic')" />
                    </div>

                    <div>
                        <x-input-label for="phone_number" value="Phone Number" />
                        <x-text-input id="phone_number" name="phone_number" type="text" class="block w-full font-mono"
                            :value="old('phone_number')" maxlength="11" placeholder="03001234567" />
                        <x-input-error :messages="$errors->get('phone_number')" />
                    </div>

                    <div>
                        <x-input-label for="address" value="Address" />
                        <textarea id="address" name="address" rows="2"
                            class="block w-full bg-iot-surface2 border border-iot-border text-iot-text placeholder:text-iot-muted
                                   rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50 resize-none"
                            placeholder="Full address">{{ old('address') }}</textarea>
                        <x-input-error :messages="$errors->get('address')" />
                    </div>
                </div>

                <div class="flex gap-3 pt-2 border-t border-iot-border">
                    <x-primary-button>Create User</x-primary-button>
                    <a href="{{ route('users.index') }}"
                       class="px-6 py-2.5 rounded-xl text-sm font-medium bg-iot-surface2 text-iot-muted border border-iot-border
                              hover:text-white hover:bg-iot-border transition-colors">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>

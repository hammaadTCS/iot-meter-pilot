<x-app-layout>
    <x-page-header title="Edit User" :subtitle="'Editing: ' . $user->name" />

    <div class="max-w-2xl">
        <div class="bg-iot-surface border border-iot-border rounded-2xl p-6 sm:p-8">
            <form action="{{ route('users.update', $user) }}" method="POST" class="space-y-5">
                @csrf
                @method('PATCH')

                <div class="space-y-4">
                    <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted border-b border-iot-border pb-2">Account</p>

                    <div>
                        <x-input-label for="name" value="Full Name" />
                        <x-text-input id="name" name="name" type="text" class="block w-full"
                            :value="old('name', $user->name)" required />
                        <x-input-error :messages="$errors->get('name')" />
                    </div>

                    <div>
                        <x-input-label for="email" value="Email" />
                        <x-text-input id="email" name="email" type="email" class="block w-full"
                            :value="old('email', $user->email)" required />
                        <x-input-error :messages="$errors->get('email')" />
                    </div>

                    <div>
                        <x-input-label value="Access" />
                        <div class="mt-1 flex items-center gap-2 flex-wrap">
                            @forelse($user->roles as $bundle)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-mono font-bold
                                             bg-iot-accent/15 text-iot-accent border border-iot-accent/30">{{ $bundle->name }}</span>
                            @empty
                                <span class="text-xs text-iot-muted">No bundles — direct grants only</span>
                            @endforelse
                            @can('users.manage_permissions')
                                @unless($user->hasRole('super_admin'))
                                    <a href="{{ route('users.permissions.show', $user) }}"
                                       class="text-xs text-iot-accent hover:text-iot-accent/80 transition-colors">Manage Access →</a>
                                @endunless
                            @endcan
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted border-b border-iot-border pb-2">Profile</p>

                    <div>
                        <x-input-label for="cnic" value="CNIC" />
                        <x-text-input id="cnic" name="cnic" type="text" class="block w-full font-mono"
                            :value="old('cnic', $user->cnic)" maxlength="13" placeholder="1234512345671" />
                        <x-input-error :messages="$errors->get('cnic')" />
                    </div>

                    <div>
                        <x-input-label for="phone_number" value="Phone Number" />
                        <x-text-input id="phone_number" name="phone_number" type="text" class="block w-full font-mono"
                            :value="old('phone_number', $user->phone_number)" maxlength="11" placeholder="03001234567" />
                        <x-input-error :messages="$errors->get('phone_number')" />
                    </div>

                    <div>
                        <x-input-label for="address" value="Address" />
                        <textarea id="address" name="address" rows="2"
                            class="block w-full bg-iot-surface2 border border-iot-border text-iot-text placeholder:text-iot-muted
                                   rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50 resize-none">{{ old('address', $user->address) }}</textarea>
                        <x-input-error :messages="$errors->get('address')" />
                    </div>
                </div>

                <div class="flex gap-3 pt-2 border-t border-iot-border">
                    <x-primary-button>Save Changes</x-primary-button>
                    <a href="{{ route('users.show', $user) }}"
                       class="px-6 py-2.5 rounded-xl text-sm font-medium bg-iot-surface2 text-iot-muted border border-iot-border
                              hover:text-white hover:bg-iot-border transition-colors">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>

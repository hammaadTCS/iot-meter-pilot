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

                    @if(auth()->user()->isSuperAdmin())
                        <div>
                            <x-input-label for="role" value="Role" />
                            <select id="role" name="role"
                                class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm
                                       focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                                       @error('role') border-iot-red @enderror">
                                <option value="user"        {{ old('role', $user->role) === 'user'        ? 'selected' : '' }}>User</option>
                                <option value="admin"       {{ old('role', $user->role) === 'admin'       ? 'selected' : '' }}>Admin</option>
                                <option value="super_admin" {{ old('role', $user->role) === 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                            </select>
                            <p class="text-xs text-iot-amber mt-1">Role changes take effect on the user's next login.</p>
                            <x-input-error :messages="$errors->get('role')" />
                        </div>
                    @else
                        <div>
                            <x-input-label value="Role" />
                            <div class="mt-1">
                                <x-role-badge :role="$user->role" />
                                <span class="text-xs text-iot-muted ml-2">Only super admins can change roles</span>
                            </div>
                        </div>
                    @endif
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

<x-app-layout>
    <x-page-header :title="$user->name">
        <x-slot name="actions">
            <a href="{{ route('users.edit', $user) }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium
                      bg-iot-surface2 text-iot-muted border border-iot-border
                      hover:text-white hover:bg-iot-border transition-colors">
                Edit User
            </a>
            @if(auth()->user()->isSuperAdmin() && !$user->isSuperAdmin())
                <form action="{{ route('users.destroy', $user) }}" method="POST"
                      onsubmit="return confirm('Delete {{ addslashes($user->name) }}?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="px-4 py-2 rounded-xl text-sm font-medium text-iot-red border border-red-500/20
                                   hover:bg-red-500/10 transition-colors">
                        Delete
                    </button>
                </form>
            @endif
        </x-slot>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- User info card --}}
        <div class="lg:col-span-1">
            <div class="bg-iot-surface border border-iot-border rounded-2xl p-6 space-y-5">
                {{-- Avatar --}}
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-2xl bg-iot-accent/20 border border-iot-accent/30
                                flex items-center justify-center flex-shrink-0">
                        <span class="font-mono text-xl font-bold text-iot-accent">
                            {{ strtoupper(substr($user->name, 0, 2)) }}
                        </span>
                    </div>
                    <div>
                        <h2 class="font-semibold text-white">{{ $user->name }}</h2>
                        <x-role-badge :role="$user->role" />
                    </div>
                </div>

                <div class="space-y-3 border-t border-iot-border pt-4">
                    <div>
                        <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted mb-1">Email</p>
                        <p class="text-sm text-iot-text">{{ $user->email }}</p>
                    </div>
                    @if($user->phone_number)
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted mb-1">Phone</p>
                            <p class="text-sm font-mono text-iot-text">{{ $user->phone_number }}</p>
                        </div>
                    @endif
                    @if($user->cnic)
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted mb-1">CNIC</p>
                            <p class="text-sm font-mono text-iot-text">{{ $user->cnic }}</p>
                        </div>
                    @endif
                    @if($user->address)
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted mb-1">Address</p>
                            <p class="text-sm text-iot-text">{{ $user->address }}</p>
                        </div>
                    @endif
                    <div>
                        <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted mb-1">Member Since</p>
                        <p class="text-sm text-iot-text">{{ $user->created_at->format('d M Y') }}</p>
                    </div>
                </div>

                {{-- Role change (super admin only) --}}
                @if(auth()->user()->isSuperAdmin())
                    <div class="border-t border-iot-border pt-4">
                        <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted mb-3">Change Role</p>
                        <form action="{{ route('users.role', $user) }}" method="POST" class="flex gap-2">
                            @csrf
                            @method('PATCH')
                            <select name="role"
                                class="flex-1 px-3 py-2 bg-iot-surface2 border border-iot-border rounded-xl text-sm text-iot-text
                                       focus:outline-none focus:border-iot-accent">
                                <option value="user"        {{ $user->role === 'user'        ? 'selected' : '' }}>User</option>
                                <option value="admin"       {{ $user->role === 'admin'       ? 'selected' : '' }}>Admin</option>
                                <option value="super_admin" {{ $user->role === 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                            </select>
                            <button type="submit"
                                    class="px-3 py-2 rounded-xl text-sm font-medium bg-iot-accent/10 text-iot-accent border border-iot-accent/20
                                           hover:bg-iot-accent/20 transition-colors whitespace-nowrap">
                                Update
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        {{-- Devices --}}
        <div class="lg:col-span-2">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="font-mono text-sm font-bold text-white">
                    Devices
                    <span class="text-iot-muted font-normal">({{ $user->devices->count() }})</span>
                </h3>
                <a href="{{ route('devices.create') }}"
                   class="text-xs text-iot-accent hover:text-iot-accent/80 transition-colors">
                    + Add Device
                </a>
            </div>

            @if($user->devices->count())
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach($user->devices as $device)
                        <x-device-card :device="$device" :showOwner="false" />
                    @endforeach
                </div>
            @else
                <div class="bg-iot-surface border border-iot-border rounded-2xl p-8 text-center">
                    <p class="text-iot-muted text-sm">This user has no devices assigned.</p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

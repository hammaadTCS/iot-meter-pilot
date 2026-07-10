<x-app-layout>
    <x-page-header title="Users" subtitle="Manage user accounts and permissions.">
        <x-slot name="actions">
            <a href="{{ route('users.create') }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium
                      bg-iot-accent text-iot-bg hover:bg-iot-accent/90 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Add User
            </a>
        </x-slot>
    </x-page-header>

    {{-- Search + filter bar --}}
    <form method="GET" action="{{ route('users.index') }}" class="flex flex-col sm:flex-row gap-3 mb-5">
        <div class="relative flex-1">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-iot-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Search name or email..."
                   class="w-full pl-9 pr-4 py-2.5 bg-iot-surface border border-iot-border rounded-xl text-sm text-iot-text
                          placeholder:text-iot-muted focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50">
        </div>
        <select name="bundle" onchange="this.form.submit()"
                class="px-4 py-2.5 bg-iot-surface border border-iot-border rounded-xl text-sm text-iot-text
                       focus:outline-none focus:border-iot-accent">
            <option value="">All Bundles</option>
            <option value="consumer"       {{ request('bundle') === 'consumer'       ? 'selected' : '' }}>Consumer</option>
            <option value="prosumer"       {{ request('bundle') === 'prosumer'       ? 'selected' : '' }}>Prosumer</option>
            <option value="field_engineer" {{ request('bundle') === 'field_engineer' ? 'selected' : '' }}>Field Engineer</option>
            <option value="fleet_operator" {{ request('bundle') === 'fleet_operator' ? 'selected' : '' }}>Fleet Operator</option>
            <option value="super_admin"    {{ request('bundle') === 'super_admin'    ? 'selected' : '' }}>Super Admin</option>
        </select>
    </form>

    @if($users->count())
        <div class="bg-iot-surface border border-iot-border rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-iot-surface2 border-b border-iot-border">
                            <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted">User</th>
                            <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted">Access</th>
                            <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted hidden md:table-cell">Devices</th>
                            <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted hidden lg:table-cell">Joined</th>
                            <th class="px-5 py-3.5 text-right font-mono text-[10px] uppercase tracking-widest text-iot-muted">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-iot-border">
                        @foreach($users as $user)
                            <tr class="hover:bg-iot-surface2 transition-colors">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-iot-accent/20 border border-iot-accent/30
                                                    flex items-center justify-center flex-shrink-0">
                                            <span class="font-mono text-xs font-bold text-iot-accent">
                                                {{ strtoupper(substr($user->name, 0, 2)) }}
                                            </span>
                                        </div>
                                        <div>
                                            <div class="font-medium text-white">{{ $user->name }}</div>
                                            <div class="text-xs text-iot-muted">{{ $user->email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        @forelse($user->roles as $bundle)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono font-bold
                                                         bg-iot-accent/15 text-iot-accent border border-iot-accent/30">{{ $bundle->name }}</span>
                                        @empty
                                            <span class="text-[10px] text-iot-muted font-mono">direct only</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="px-5 py-4 hidden md:table-cell">
                                    <span class="font-mono text-sm text-iot-text">{{ $user->devices->count() }}</span>
                                </td>
                                <td class="px-5 py-4 text-xs text-iot-muted hidden lg:table-cell">
                                    {{ $user->created_at->format('d M Y') }}
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('users.show', $user) }}"
                                           class="px-3 py-1.5 rounded-lg text-xs font-medium text-iot-muted border border-iot-border
                                                  hover:text-white hover:bg-iot-surface2 transition-colors">
                                            View
                                        </a>
                                        <a href="{{ route('users.edit', $user) }}"
                                           class="px-3 py-1.5 rounded-lg text-xs font-medium text-iot-muted border border-iot-border
                                                  hover:text-white hover:bg-iot-surface2 transition-colors">
                                            Edit
                                        </a>
                                        @can('users.manage_permissions')
                                            @unless($user->hasRole('super_admin'))
                                                <a href="{{ route('users.permissions.show', $user) }}"
                                                   class="px-3 py-1.5 rounded-lg text-xs font-medium text-iot-accent border border-iot-accent/30
                                                          hover:bg-iot-accent/10 transition-colors">
                                                    Access
                                                </a>
                                            @endunless
                                        @endcan
                                        @if(auth()->user()->isSuperAdmin() && !$user->isSuperAdmin())
                                            <form action="{{ route('users.destroy', $user) }}" method="POST"
                                                  onsubmit="return confirm('Delete {{ addslashes($user->name) }}? This cannot be undone.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="px-3 py-1.5 rounded-lg text-xs font-medium text-iot-red border border-red-500/20
                                                               hover:bg-red-500/10 transition-colors">
                                                    Delete
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $users->links() }}
        </div>
    @else
        <div class="flex flex-col items-center justify-center py-20 text-center">
            <div class="w-16 h-16 rounded-2xl bg-iot-surface2 border border-iot-border flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-iot-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
            </div>
            <h3 class="font-mono text-base font-bold text-white mb-2">No users found</h3>
            <p class="text-sm text-iot-muted">Try adjusting your search or filters.</p>
        </div>
    @endif
</x-app-layout>

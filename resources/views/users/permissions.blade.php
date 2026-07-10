<x-app-layout>
    <x-page-header title="Manage Access" :subtitle="$user->name . ' — ' . $user->email" />

    <div class="max-w-3xl space-y-6">

        {{-- Assigned bundles + detach actions --}}
        <div class="bg-iot-surface border border-iot-border rounded-2xl p-6">
            <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted border-b border-iot-border pb-2 mb-4">
                Assigned Bundles
            </p>
            @forelse ($assignedBundles as $bundle)
                <div class="flex items-center justify-between py-1.5">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-mono font-bold
                                 bg-iot-accent/15 text-iot-accent border border-iot-accent/30">
                        {{ $bundle }}
                    </span>
                    <form action="{{ route('users.permissions.detach', $user) }}" method="POST"
                          onsubmit="return confirm('Detach {{ $bundle }}? Its permissions stay as direct grants you can then edit one by one.')">
                        @csrf
                        <input type="hidden" name="bundle" value="{{ $bundle }}">
                        <button type="submit"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium text-iot-muted border border-iot-border
                                       hover:text-white hover:bg-iot-surface2 transition-colors">
                            Detach → direct grants
                        </button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-iot-amber">No bundles — this account has only its direct grants below.</p>
            @endforelse
            <p class="text-xs text-iot-muted mt-3">
                Detaching converts a bundle into individually editable grants — use it for
                "consumer, but without …" exceptions. Bundle edits made below apply on save.
            </p>
        </div>

        <form action="{{ route('users.permissions.update', $user) }}" method="POST" class="space-y-6">
            @csrf
            @method('PATCH')

            {{-- Bundle membership --}}
            <div class="bg-iot-surface border border-iot-border rounded-2xl p-6">
                <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted border-b border-iot-border pb-2 mb-4">
                    Bundles
                </p>
                <div class="grid sm:grid-cols-2 gap-3">
                    @foreach ($bundles as $bundle)
                        <label class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-colors
                                      {{ in_array($bundle->name, $assignedBundles) ? 'border-iot-accent/40 bg-iot-accent/5' : 'border-iot-border hover:bg-iot-surface2' }}">
                            <input type="checkbox" name="bundles[]" value="{{ $bundle->name }}"
                                   @checked(in_array($bundle->name, $assignedBundles))
                                   class="mt-0.5 rounded border-iot-border bg-iot-surface2 text-iot-accent focus:ring-iot-accent/50">
                            <span>
                                <span class="block text-sm font-medium text-white font-mono">{{ $bundle->name }}</span>
                                <span class="block text-xs text-iot-muted mt-0.5">{{ $bundle->permissions->count() }} permissions</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('bundles.*')" class="mt-2" />
            </div>

            {{-- Direct grants, grouped by area --}}
            <div class="bg-iot-surface border border-iot-border rounded-2xl p-6">
                <p class="font-mono text-[10px] uppercase tracking-widest text-iot-muted border-b border-iot-border pb-2 mb-1">
                    Individual Permissions
                </p>
                <p class="text-xs text-iot-muted mb-4">
                    Locked entries come from a bundle above — detach the bundle to edit them individually.
                    Ticked entries here are direct grants on top of the bundles.
                </p>

                @foreach ($catalog as $group => $slugs)
                    <p class="font-mono text-[10px] uppercase tracking-widest text-iot-accent2 mt-4 mb-2">{{ $group }}</p>
                    <div class="grid sm:grid-cols-2 gap-x-6 gap-y-1.5">
                        @foreach ($slugs as $slug)
                            @php $via = $viaBundles[$slug] ?? []; @endphp
                            <label class="flex items-center gap-2.5 text-sm {{ $via ? 'opacity-60' : 'cursor-pointer' }}">
                                @if ($via)
                                    <input type="checkbox" checked disabled
                                           class="rounded border-iot-border bg-iot-surface2 text-iot-accent">
                                    <span class="text-iot-text font-mono text-xs">{{ $slug }}</span>
                                    <span class="text-[10px] text-iot-muted whitespace-nowrap">via {{ implode(', ', $via) }}</span>
                                @else
                                    <input type="checkbox" name="direct[]" value="{{ $slug }}"
                                           @checked(in_array($slug, $directGrants))
                                           class="rounded border-iot-border bg-iot-surface2 text-iot-accent focus:ring-iot-accent/50">
                                    <span class="text-iot-text font-mono text-xs">{{ $slug }}</span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                @endforeach

                {{-- Built-ins as direct grants (visible only after a detach) --}}
                @php $directBuiltIns = array_intersect($directGrants, $builtIn); @endphp
                @if ($directBuiltIns)
                    <p class="font-mono text-[10px] uppercase tracking-widest text-iot-accent2 mt-4 mb-2">Built-in (from detached bundle)</p>
                    <div class="grid sm:grid-cols-2 gap-x-6 gap-y-1.5">
                        @foreach ($builtIn as $slug)
                            @php $via = $viaBundles[$slug] ?? []; @endphp
                            @if (! $via)
                                <label class="flex items-center gap-2.5 text-sm cursor-pointer">
                                    <input type="checkbox" name="direct[]" value="{{ $slug }}"
                                           @checked(in_array($slug, $directGrants))
                                           class="rounded border-iot-border bg-iot-surface2 text-iot-accent focus:ring-iot-accent/50">
                                    <span class="text-iot-text font-mono text-xs">{{ $slug }}</span>
                                </label>
                            @endif
                        @endforeach
                    </div>
                @endif
                <x-input-error :messages="$errors->get('direct.*')" class="mt-2" />
            </div>

            <div class="flex gap-3">
                <x-primary-button>Save Access</x-primary-button>
                <a href="{{ route('users.show', $user) }}"
                   class="px-6 py-2.5 rounded-xl text-sm font-medium bg-iot-surface2 text-iot-muted border border-iot-border
                          hover:text-white hover:bg-iot-border transition-colors">
                    Back to Profile
                </a>
            </div>
        </form>
    </div>
</x-app-layout>

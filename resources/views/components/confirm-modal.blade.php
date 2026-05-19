@props([
    'title'       => 'Confirm Delete',
    'message'     => 'Are you sure? This action cannot be undone.',
    'confirmText' => 'Delete',
    'cancelText'  => 'Cancel',
])

{{--
Usage: wrap a page in x-data with confirmOpen, confirmAction, confirmLabel.
Trigger: @click="openConfirm(url, label)"
--}}
<div
    x-show="confirmOpen ?? false"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
    style="display: none;"
    @keydown.escape.window="confirmOpen = false"
>
    <div
        x-show="confirmOpen ?? false"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        @click.outside="confirmOpen = false"
        class="w-full max-w-md bg-iot-surface border border-iot-border rounded-2xl p-6 shadow-2xl shadow-black/50"
    >
        {{-- Icon --}}
        <div class="w-12 h-12 rounded-full bg-red-500/10 border border-red-500/20
                    flex items-center justify-center mx-auto mb-4">
            <svg class="w-6 h-6 text-iot-red" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>

        <h3 class="font-mono text-base font-bold text-white text-center mb-2">{{ $title }}</h3>
        <p class="text-sm text-iot-muted text-center mb-1">{{ $message }}</p>
        <p x-show="confirmLabel" class="font-mono text-xs text-white text-center font-bold" x-text='"\"" + confirmLabel + "\""'></p>

        <div class="flex gap-3 mt-6">
            <button
                @click="confirmOpen = false"
                class="flex-1 px-4 py-2.5 rounded-xl text-sm font-medium
                       bg-iot-surface2 text-iot-text border border-iot-border
                       hover:bg-iot-border transition-colors">
                {{ $cancelText }}
            </button>
            <form :action="confirmAction" method="POST" class="flex-1">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="w-full px-4 py-2.5 rounded-xl text-sm font-medium
                               bg-red-500/10 text-iot-red border border-red-500/25
                               hover:bg-red-500/20 transition-colors">
                    {{ $confirmText }}
                </button>
            </form>
        </div>
    </div>
</div>

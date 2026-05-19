<div
    x-data="{
        toasts: [],
        add(message, type = 'success') {
            const id = Date.now();
            this.toasts.push({ id, message, type });
            setTimeout(() => this.remove(id), 4500);
        },
        remove(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        },
        init() {
            @if(session('success'))
                this.add(@js(session('success')), 'success');
            @endif
            @if(session('error'))
                this.add(@js(session('error')), 'error');
            @endif
            @if(session('warning'))
                this.add(@js(session('warning')), 'warning');
            @endif
        }
    }"
    @toast.window="add($event.detail.message, $event.detail.type ?? 'success')"
    class="fixed bottom-5 right-5 z-50 flex flex-col gap-2 pointer-events-none"
    style="max-width: 360px;"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="true"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-2 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 translate-y-2 scale-95"
            class="pointer-events-auto flex items-start gap-3 px-4 py-3 rounded-xl border shadow-2xl shadow-black/50 text-sm"
            :class="{
                'bg-iot-surface border-green-500/25 text-iot-text':   toast.type === 'success',
                'bg-iot-surface border-red-500/25 text-iot-text':     toast.type === 'error',
                'bg-iot-surface border-amber-500/25 text-iot-text':   toast.type === 'warning',
                'bg-iot-surface border-iot-accent/25 text-iot-text':  toast.type === 'info',
            }"
        >
            {{-- Icon --}}
            <span class="flex-shrink-0 mt-0.5">
                <svg x-show="toast.type === 'success'" class="w-4 h-4 text-iot-green" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                <svg x-show="toast.type === 'error'" class="w-4 h-4 text-iot-red" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
                <svg x-show="toast.type === 'warning'" class="w-4 h-4 text-iot-amber" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <svg x-show="toast.type === 'info'" class="w-4 h-4 text-iot-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </span>
            {{-- Message --}}
            <p class="flex-1 font-body text-sm" x-text="toast.message"></p>
            {{-- Close --}}
            <button @click="remove(toast.id)"
                    class="flex-shrink-0 text-iot-muted hover:text-white transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </template>
</div>

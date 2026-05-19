@props(['title', 'subtitle' => null, 'actions' => null])

<div class="flex items-start justify-between gap-4 mb-6">
    <div>
        <h1 class="font-mono text-xl font-bold text-white tracking-tight">{{ $title }}</h1>
        @if($subtitle)
            <p class="mt-1 text-sm text-iot-muted">{{ $subtitle }}</p>
        @endif
    </div>
    @if($actions)
        <div class="flex items-center gap-2 flex-shrink-0">
            {{ $actions }}
        </div>
    @endif
</div>

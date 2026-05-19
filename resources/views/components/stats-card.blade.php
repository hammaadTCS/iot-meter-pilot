@props([
    'value',
    'label',
    'icon'   => null,
    'color'  => 'iot-accent',
    'href'   => null,
    'change' => null,
])

@php $tag = $href ? 'a' : 'div'; @endphp

<{{ $tag }}
    {{ $href ? "href=$href" : '' }}
    @class([
        'bg-iot-surface border border-iot-border rounded-2xl p-5 group transition-all duration-150',
        'hover:-translate-y-0.5 hover:border-iot-border hover:shadow-lg hover:shadow-black/20' => (bool)$href,
    ])>
    <div class="flex items-start justify-between">
        <div>
            <p class="font-mono text-[11px] uppercase tracking-widest text-iot-muted mb-2">{{ $label }}</p>
            <p class="font-mono text-3xl font-bold text-white">{{ $value }}</p>
            @if($change !== null)
                <p class="mt-1.5 text-xs text-iot-muted">{{ $change }}</p>
            @endif
        </div>
        @if($icon)
        <div class="p-2.5 rounded-xl bg-iot-surface2 border border-iot-border text-iot-muted
                    group-hover:text-iot-accent group-hover:border-iot-accent/30 transition-colors">
            {!! $icon !!}
        </div>
        @endif
    </div>
</{{ $tag }}>

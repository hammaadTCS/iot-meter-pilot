@props(['label'])

<div class="pt-4 first:pt-0">
    <p class="px-3 mb-1 font-mono text-[10px] uppercase tracking-widest text-iot-muted select-none">
        {{ $label }}
    </p>
    {{ $slot }}
</div>

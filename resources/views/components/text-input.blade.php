@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-iot-surface2 border border-iot-border text-iot-text placeholder:text-iot-muted rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50 disabled:opacity-50 disabled:cursor-not-allowed']) }}>

<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-5 py-2.5 bg-red-500/10 border border-red-500/25 text-iot-red rounded-xl font-semibold text-sm hover:bg-red-500/20 focus:outline-none focus:ring-2 focus:ring-iot-red/50 transition-colors']) }}>
    {{ $slot }}
</button>

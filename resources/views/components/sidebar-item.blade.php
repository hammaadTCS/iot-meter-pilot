@props(['href', 'active' => false])

<a href="{{ $href }}"
   @class([
       'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-all duration-150',
       'bg-iot-accent/10 text-iot-accent border-l-2 border-iot-accent ml-[-1px] pl-[11px]' => $active,
       'text-iot-muted hover:text-white hover:bg-iot-surface2 border-l-2 border-transparent' => !$active,
   ])>
    {{ $slot }}
</a>

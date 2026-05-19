import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/livewire/livewire/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
    ],

    safelist: [
        'text-iot-green', 'text-iot-amber', 'text-iot-red', 'text-iot-muted',
        'text-iot-accent', 'text-iot-accent2',
        'bg-green-500/10', 'bg-amber-500/10', 'bg-red-500/10', 'bg-slate-500/10',
        'border-green-500/25', 'border-amber-500/25', 'border-red-500/25',
        'bg-iot-accent/15', 'bg-iot-accent2/15', 'bg-slate-500/15',
        'border-iot-accent/30', 'border-iot-accent2/30', 'border-slate-500/30',
        'shadow-green-500/30', 'shadow-amber-500/30', 'shadow-red-500/30',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
                mono: ['"Space Mono"', 'monospace'],
                body: ['"DM Sans"', 'sans-serif'],
            },
            colors: {
                iot: {
                    bg:       '#0b0f1a',
                    surface:  '#111827',
                    surface2: '#1a2235',
                    border:   '#1f2d45',
                    accent:   '#00e5ff',
                    accent2:  '#7c3aed',
                    green:    '#10b981',
                    amber:    '#f59e0b',
                    red:      '#ef4444',
                    text:     '#e2e8f0',
                    muted:    '#64748b',
                },
            },
        },
    },

    plugins: [forms],
};

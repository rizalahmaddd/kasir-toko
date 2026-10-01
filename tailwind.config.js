import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    darkMode: 'class',
    theme: {
        extend: {
            // Inter: legible at the small, dense sizes this ops tool runs at (tables, KPI
            // numbers, form labels), not the framework default. See DESIGN.md "Tipografi".
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                slate: {
                    50: 'rgb(var(--color-slate-50) / <alpha-value>)',
                    100: 'rgb(var(--color-slate-100) / <alpha-value>)',
                    200: 'rgb(var(--color-slate-200) / <alpha-value>)',
                    300: 'rgb(var(--color-slate-300) / <alpha-value>)',
                    400: 'rgb(var(--color-slate-400) / <alpha-value>)',
                    500: 'rgb(var(--color-slate-500) / <alpha-value>)',
                    600: 'rgb(var(--color-slate-600) / <alpha-value>)',
                    700: 'rgb(var(--color-slate-700) / <alpha-value>)',
                    800: 'rgb(var(--color-slate-800) / <alpha-value>)',
                    900: 'rgb(var(--color-slate-900) / <alpha-value>)',
                    950: 'rgb(var(--color-slate-950) / <alpha-value>)',
                },
            },
        },
    },

    plugins: [forms],
};

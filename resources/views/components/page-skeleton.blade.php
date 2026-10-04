@props(['type' => 'list'])

@php
    $card = 'bg-slate-900/80 border border-slate-800/80 rounded-2xl p-4';
    $tableRows = 7;
@endphp

<div {{ $attributes->merge(['class' => 'space-y-6']) }} aria-hidden="true">
    @switch($type)
        @case('dashboard')
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 sm:gap-4">
                @for ($i = 0; $i < 4; $i++)
                    <div class="{{ $card }} space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="sk sk-text w-24"></span>
                            <span class="sk w-9 h-9 rounded-xl"></span>
                        </div>
                        <span class="sk sk-text w-32 text-xl"></span>
                        <span class="sk sk-text w-20"></span>
                    </div>
                @endfor
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="{{ $card }} lg:col-span-2 space-y-4">
                    <span class="sk sk-text w-40"></span>
                    <div class="sk h-56 sm:h-64 rounded-xl"></div>
                </div>
                <div class="{{ $card }} space-y-3">
                    <span class="sk sk-text w-32"></span>
                    @for ($i = 0; $i < 5; $i++)
                        <div class="flex items-center gap-3">
                            <span class="sk w-8 h-8 rounded-lg shrink-0"></span>
                            <div class="flex-1 space-y-1.5">
                                <span class="sk sk-text w-3/4"></span>
                                <span class="sk sk-text w-1/2"></span>
                            </div>
                        </div>
                    @endfor
                </div>
            </div>
            @break

        @case('detail')
            <div class="{{ $card }} space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                    <div class="space-y-2">
                        <span class="sk sk-text w-24"></span>
                        <div class="flex items-center gap-2">
                            <span class="sk sk-text w-48 text-lg"></span>
                            <span class="sk h-5 w-16 rounded-full"></span>
                        </div>
                        <span class="sk sk-text w-64 max-w-full"></span>
                    </div>
                    <div class="flex gap-2">
                        <span class="sk h-11 sm:h-[38px] w-24 rounded-lg"></span>
                        <span class="sk h-11 sm:h-[38px] w-28 rounded-lg"></span>
                    </div>
                </div>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 pt-4 border-t border-slate-800">
                    @for ($i = 0; $i < 4; $i++)
                        <div class="bg-slate-950/60 border border-slate-800 rounded-xl p-3 space-y-2">
                            <span class="sk sk-text w-20"></span>
                            <span class="sk sk-text w-28"></span>
                        </div>
                    @endfor
                </div>
            </div>
            <x-page-skeleton.table :rows="5" :cols="6" />
            @break

        @case('report')
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                <span class="sk sk-text w-64 max-w-full"></span>
                <div class="flex flex-wrap gap-2">
                    <span class="sk h-11 sm:h-[38px] w-36 rounded-lg"></span>
                    <span class="sk h-11 sm:h-[38px] w-36 rounded-lg"></span>
                    <span class="sk h-11 sm:h-[38px] w-24 rounded-lg"></span>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                @for ($i = 0; $i < 3; $i++)
                    <div class="{{ $card }} space-y-2.5">
                        <span class="sk sk-text w-28"></span>
                        <span class="sk sk-text w-36 text-xl"></span>
                    </div>
                @endfor
            </div>
            <x-page-skeleton.table :rows="$tableRows" :cols="6" />
            @break

        @case('form')
            @for ($s = 0; $s < 2; $s++)
                <div class="{{ $card }} space-y-4">
                    <div class="space-y-1.5 pb-3 border-b border-slate-800/80">
                        <span class="sk sk-text w-40"></span>
                        <span class="sk sk-text w-72 max-w-full"></span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @for ($i = 0; $i < 4; $i++)
                            <div class="space-y-2">
                                <span class="sk sk-text w-24"></span>
                                <span class="sk block h-11 sm:h-10 rounded-lg"></span>
                            </div>
                        @endfor
                    </div>
                </div>
            @endfor
            @break

        @default
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 sm:gap-4">
                <span class="sk sk-text w-72 max-w-full"></span>
                <div class="flex flex-wrap sm:flex-nowrap items-center gap-2 sm:gap-3">
                    <span class="sk h-11 sm:h-[38px] basis-full sm:basis-auto sm:w-64 rounded-lg"></span>
                    <span class="sk h-11 sm:h-[38px] w-24 rounded-lg"></span>
                    <span class="sk h-11 sm:h-[38px] w-28 rounded-lg"></span>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                @for ($i = 0; $i < 3; $i++)
                    <div class="{{ $card }} flex items-center justify-between">
                        <div class="space-y-2.5">
                            <span class="sk sk-text w-28"></span>
                            <span class="sk sk-text w-16 text-xl"></span>
                        </div>
                        <span class="sk w-10 h-10 rounded-xl"></span>
                    </div>
                @endfor
            </div>
            <x-page-skeleton.table :rows="$tableRows" :cols="6" />
    @endswitch
</div>

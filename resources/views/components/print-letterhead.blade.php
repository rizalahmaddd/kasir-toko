@props(['title', 'number' => null, 'date' => null])

@php
    use App\Models\Setting;
@endphp

<div class="flex justify-between items-start border-b-2 border-slate-900 pb-4 mb-4">
    <div>
        <h1 class="font-extrabold text-xl text-slate-900 tracking-wide uppercase">{{ \App\Support\Branding::companyName() }}</h1>
        @if (Setting::get('company_tagline'))
            <p class="text-xs text-slate-600">{{ Setting::get('company_tagline') }}</p>
        @endif
        @if (Setting::get('company_address') || Setting::get('company_phone'))
            <p class="text-[11px] text-slate-500 mt-1">
                {{ Setting::get('company_address') }}
                @if (Setting::get('company_address') && Setting::get('company_phone')) &bull; @endif
                @if (Setting::get('company_phone')) Telp: {{ Setting::get('company_phone') }} @endif
            </p>
        @endif
    </div>
    <div class="flex items-start gap-3">
        <div class="text-right">
            <div class="doc-title-badge no-dark-invert inline-block bg-slate-900 !text-white font-mono font-bold text-xs px-3 py-1 rounded uppercase">
                {{ $title }}
            </div>
            @if ($number)
                <div class="text-xs font-bold text-slate-800 mt-2">{{ $number }}</div>
            @endif
            @if ($date)
                <div class="text-[11px] text-slate-500">{{ $date }}</div>
            @endif
        </div>
        {{ $slot }}
    </div>
</div>

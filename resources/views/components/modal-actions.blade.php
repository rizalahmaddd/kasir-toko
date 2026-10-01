<div {{ $attributes->merge(['class' => 'flex flex-col-reverse sm:flex-row sm:justify-end gap-2.5 pt-3 border-t border-slate-800/80 [&>*]:w-full sm:[&>*]:w-auto']) }}>
    {{ $slot }}
</div>

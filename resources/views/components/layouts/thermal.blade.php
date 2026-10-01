@props(['title', 'width' => '58', 'autoPrint' => false, 'embedded' => false])

{{-- Kertas struk printer thermal: selalu terang, tanpa font web supaya cetak tidak menunggu unduhan. --}}
<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }}</title>
        <style>
            @page { size: {{ $width }}mm auto; margin: 0; }
            * { box-sizing: border-box; }
            html, body { margin: 0; padding: 0; background: #e2e8f0; color: #000; }
            body { font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, "Liberation Mono", monospace; font-size: {{ $width === '80' ? '12px' : '11px' }}; line-height: 1.35; }
            .paper { width: {{ $width }}mm; margin: 16px auto; padding: 3mm {{ $width === '80' ? '4mm' : '2.5mm' }}; background: #fff; box-shadow: 0 2px 10px rgba(15, 23, 42, .2); }
            .center { text-align: center; }
            .right { text-align: right; }
            .bold { font-weight: 700; }
            .big { font-size: 1.25em; }
            .muted { color: #333; }
            .sep { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
            .row { display: flex; justify-content: space-between; gap: 6px; }
            .row > span:last-child { text-align: right; white-space: nowrap; }
            .item { margin-bottom: 4px; }
            .stamp { border: 2px solid #000; padding: 2px 6px; display: inline-block; font-weight: 700; letter-spacing: .1em; margin: 4px 0; }
            .logo { max-height: 40px; max-width: 70%; margin: 0 auto 4px; display: block; }
            .toolbar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #0f172a; font-family: system-ui, sans-serif; }
            .toolbar button { min-height: 44px; padding: 0 16px; border-radius: 8px; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; font-weight: 600; font-size: 13px; cursor: pointer; }
            .toolbar button.primary { background: #059669; border-color: #059669; color: #020617; }
            @media print {
                html, body { background: #fff; }
                .paper { margin: 0; box-shadow: none; width: 100%; }
                .toolbar { display: none; }
            }
        </style>
    </head>
    <body>
        @unless ($embedded)
            <div class="toolbar">
                <button type="button" class="primary" onclick="window.print()">Cetak</button>
                <button type="button" onclick="closeReceipt()">Tutup</button>
            </div>
        @endunless

        <div class="paper">
            {{ $slot }}
        </div>

        <script>
            function closeReceipt() {
                window.close();
                setTimeout(function () {
                    if (window.history.length > 1) {
                        window.history.back();
                    } else if (document.referrer) {
                        window.location.href = document.referrer;
                    } else {
                        window.location.href = "{{ route('dashboard') }}";
                    }
                }, 150);
            }
        </script>

        @if ($autoPrint && ! $embedded)
            <script>
                window.addEventListener('load', function () {
                    setTimeout(function () { window.print(); }, 150);
                });
                window.addEventListener('afterprint', function () {
                    closeReceipt();
                });
            </script>
        @endif
    </body>
</html>

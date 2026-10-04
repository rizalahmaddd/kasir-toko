<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page {
            margin: 28px 32px 36px 32px;
        }
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif;
            font-size: 9px;
            color: #1e293b;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }
        .header {
            border-bottom: 2px solid #047857;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .company-name {
            font-size: 14px;
            font-weight: bold;
            color: #047857;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }
        .company-tagline {
            font-size: 8px;
            color: #64748b;
            margin: 2px 0 0 0;
        }
        .company-contact {
            font-size: 7.5px;
            color: #64748b;
            margin: 2px 0 0 0;
        }
        .report-header {
            margin-bottom: 12px;
        }
        .report-title {
            font-size: 13px;
            font-weight: bold;
            color: #0f172a;
            margin: 0 0 2px 0;
        }
        .report-meta {
            font-size: 8px;
            color: #64748b;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
        }
        table.data-table th.text-right {
            text-align: right;
        }
        .nowrap {
            white-space: nowrap;
        }
        table.data-table th {
            background-color: #047857;
            color: #ffffff;
            font-weight: bold;
            text-align: left;
            padding: 5px 6px;
            border: 1px solid #047857;
            text-transform: uppercase;
            font-size: 7.5px;
            letter-spacing: 0.3px;
        }
        table.data-table td {
            padding: 5px 6px;
            border: 1px solid #cbd5e1;
            vertical-align: top;
        }
        table.data-table tr:nth-child(even) td {
            background-color: #f8fafc;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .font-mono {
            font-family: monospace;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            border-top: 1px solid #e2e8f0;
            padding-top: 4px;
            font-size: 7.5px;
            color: #94a3b8;
            text-align: right;
        }
        .page-number:before {
            content: "Halaman " counter(page);
        }
    </style>
</head>
<body>
    <div class="header">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="vertical-align: top;">
                    <h1 class="company-name">{{ $companyName }}</h1>
                    @if ($companyTagline)
                        <p class="company-tagline">{{ $companyTagline }}</p>
                    @endif
                    @if ($companyAddress || $companyPhone)
                        <p class="company-contact">
                            {{ $companyAddress }}
                            @if ($companyAddress && $companyPhone) &bull; @endif
                            @if ($companyPhone) Telp: {{ $companyPhone }} @endif
                        </p>
                    @endif
                </td>
                <td style="text-align: right; vertical-align: top;">
                    <div style="font-size: 8px; font-weight: bold; color: #047857; text-transform: uppercase;">Laporan Sistem</div>
                    <div style="font-size: 7.5px; color: #64748b; margin-top: 2px;">Waktu Cetak: {{ $exportedAt }}</div>
                    @if ($user)
                        <div style="font-size: 7.5px; color: #64748b;">Dicetak Oleh: {{ $user->name }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="report-header">
        <h2 class="report-title">{{ $title }}</h2>
        @if ($subtitle)
            <div class="report-meta">{{ $subtitle }}</div>
        @endif
        <div class="report-meta" style="margin-top: 3px;">
            Total Data: <strong>{{ count($rows) }} baris</strong> (Diekspor lengkap tanpa paginasi)
        </div>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 28px; text-align: center;">No</th>
                @foreach ($headers as $header)
                    <th @class(['text-right' => $numericColumns[$loop->index] ?? false])>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $index => $row)
                <tr>
                    <td class="text-center" style="color: #64748b;">{{ $index + 1 }}</td>
                    @foreach (array_values((array) $row) as $col => $val)
                        <td @class(['text-right nowrap' => ($numericColumns[$col] ?? false) && \App\Support\ExportNumber::parse($val) !== null])>{{ $val }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) + 1 }}" style="text-align: center; padding: 15px; color: #64748b;">
                        Tidak ada data untuk diekspor.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <table style="width: 100%;">
            <tr>
                <td style="text-align: left; color: #94a3b8; font-size: 7.5px;">
                    {{ $companyName }} &bull; Dokumen Rahasia Perusahaan
                </td>
                <td style="text-align: right; color: #94a3b8; font-size: 7.5px;">
                    <span class="page-number"></span>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>

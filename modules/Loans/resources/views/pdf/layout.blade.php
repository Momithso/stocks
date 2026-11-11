{{--
    Common frame of the loan documents (see Modules\Loans\Support\LoanDocuments): head with print logo,
    organization and number, a footer with the checksum of the signed content.
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>@yield('title') {{ $doc['number'] }}</title>
    <style>
        @page { margin: 18mm 20mm 22mm 25mm; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; line-height: 1.35; }
        .muted { color: #555; }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .nowrap { white-space: nowrap; }
        .footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 6.5pt; color: #555; }
        h1 { font-size: 14pt; margin: 4mm 0 1mm 0; }
        h2 { font-size: 10.5pt; margin: 4mm 0 1mm 0; }
        h3 { font-size: 9.5pt; margin: 3mm 0 1mm 0; }
        p { margin: 0 0 1.5mm 0; }
        ol, ul { margin: 0 0 1.5mm 0; padding-left: 6mm; }
        li { margin-bottom: 0.8mm; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        table.data th { text-align: left; font-size: 7pt; text-transform: uppercase; color: #555; border-bottom: 0.6pt solid #999; padding: 1.2mm; }
        table.data td { border-bottom: 0.4pt solid #ddd; padding: 1.2mm; vertical-align: top; }
        table.data tr { page-break-inside: avoid; }
        .check { width: 3.2mm; height: 3.2mm; border: 0.6pt solid #333; display: inline-block; }
        .persons td { vertical-align: top; padding: 0 6mm 2mm 0; }
        .signatures { width: 100%; margin-top: 8mm; page-break-inside: avoid; }
        .signatures td { width: 50%; vertical-align: bottom; padding-right: 8mm; }
        .signature-line { border-top: 0.6pt solid #333; padding-top: 1mm; font-size: 7pt; color: #555; }
        .signature-image { height: 18mm; }
        .stamp { border: 0.8pt solid #b45309; color: #b45309; padding: 1.5mm 3mm; font-weight: bold; display: inline-block; margin: 2mm 0; }
    </style>
</head>
<body>
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            @if ($logo)
                <td style="width: 22mm; vertical-align: top;"><img src="{{ $logo }}" style="max-width: 18mm; max-height: 18mm;" alt=""></td>
            @endif
            <td style="vertical-align: top;">
                <div style="font-size: 11pt; font-weight: bold;">{{ $doc['organization'] }}</div>
                <div class="muted" style="font-size: 7.5pt;">{{ implode(' · ', $doc['address'] ?? []) }}@if (! empty($doc['contact'])) · {{ $doc['contact'] }}@endif</div>
            </td>
            <td style="width: 45mm; text-align: right; vertical-align: top; white-space: nowrap;">
                <div class="mono" style="font-size: 11pt; font-weight: bold;">{{ $doc['number'] }}</div>
                <div class="muted" style="font-size: 7.5pt;">@yield('title')</div>
            </td>
        </tr>
    </table>

    @yield('content')

    <div class="footer">
        @if ($hash)
            Prüfsumme (SHA-256) des unterschriebenen Inhalts: <span class="mono">{{ $hash }}</span>
        @else
            Entwurf ohne Unterschriften, erstellt {{ $doc['date'] ?? now()->format('d.m.Y H:i') }}
        @endif
    </div>
</body>
</html>

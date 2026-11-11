{{--
    Test label sheet of the demo data, see App\Support\Demo\TestSheet.
    Page 1: 24 labels of about 70 × 37 mm (3 × 8) without margin between them, like common A4 label sheets.
    Page 2: check list with what should happen when a label is scanned.
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Testetiketten</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 7pt; color: #111; }
        /* a little smaller than 70 × 37 mm, so the cut lines fit on the page */
        table.sheet { border-collapse: collapse; table-layout: fixed; margin: 0; }
        table.sheet td { width: 69.6mm; height: 36.4mm; padding: 0; vertical-align: top; border: 0.2pt dashed #bbb; }
        .label { padding: 2.5mm; height: 31mm; overflow: hidden; }
        .qr { float: left; width: 24mm; height: 24mm; margin-right: 2mm; }
        .number { font-family: DejaVu Sans Mono, monospace; font-size: 8.5pt; font-weight: bold; }
        .name { font-size: 7.5pt; margin-top: 0.5mm; }
        .purpose { clear: both; font-size: 6.5pt; color: #333; padding-top: 1mm; }
        .list { padding: 15mm 15mm 0 20mm; page-break-before: always; }
        .list h1 { font-size: 13pt; margin: 0 0 1mm 0; }
        .list table { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        .list th { text-align: left; font-size: 6.5pt; text-transform: uppercase; color: #555; border-bottom: 0.6pt solid #999; padding: 1.2mm; }
        .list td { border-bottom: 0.4pt solid #ddd; padding: 1.2mm; vertical-align: top; font-size: 7.5pt; }
        .mono { font-family: DejaVu Sans Mono, monospace; white-space: nowrap; }
        .muted { color: #555; }
    </style>
</head>
<body>
    <table class="sheet">
        @foreach (array_chunk($labels, 3) as $row)
            <tr>
                @foreach ($row as $label)
                    <td>
                        <div class="label">
                            <img class="qr" src="{{ $label['qr'] }}" alt="">
                            <div class="number">{{ $label['code'] }}</div>
                            <div class="name">{{ $label['name'] }}</div>
                            <div class="purpose">{{ $label['purpose'] }}</div>
                        </div>
                    </td>
                @endforeach
                @for ($empty = count($row); $empty < 3; $empty++)
                    <td></td>
                @endfor
            </tr>
        @endforeach
    </table>

    <div class="list">
        <h1>Testetiketten · Prüfliste</h1>
        <div class="muted">{{ $organization }} · Codes für {{ $address }} · Demo-Daten vom {{ \Illuminate\Support\Carbon::parse($seededAt)->format('d.m.Y') }}</div>
        <table>
            <thead>
                <tr><th>Nr.</th><th>Code</th><th>Was es ist</th><th>Beim Scan soll passieren</th></tr>
            </thead>
            <tbody>
                @foreach ($labels as $index => $label)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="mono">{{ $label['code'] }}</td>
                        <td>{{ $label['purpose'] }}<br><span class="muted">{{ $label['name'] }}</span></td>
                        <td>{{ $label['expect'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>

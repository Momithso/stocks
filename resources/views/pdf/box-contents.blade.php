{{--
    Contents of a box as A4 list, see App\Http\Controllers\Inventory\ContentsPdfController.
    As letter ($letter) the first page follows DIN 5008 form B: letterhead 45 mm, address field 20 mm from the left and
    45 mm from the top (85 × 45 mm, text from 25 mm like the rest of the page), information block from
    125 mm to the right margin, fold marks at 105 and 210 mm and
    the hole mark at 148.5 mm. Every page has a margin of 15 mm on top, so the absolute positions of the
    first page are written as "position on the page minus 15 mm".
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Inhalt {{ $box->number }}</title>
    <style>
        @page { margin: 15mm 0 20mm 0; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; }
        .abs { position: absolute; }
        .muted { color: #555; }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .mark { position: absolute; left: 5mm; width: 5mm; border-top: 0.5pt solid #555; }
        .page-footer { position: fixed; bottom: -12mm; left: 25mm; right: 20mm; font-size: 7pt; color: #555; text-align: center; }
        .content { padding: 0 20mm 0 25mm; }
        h1 { font-size: 13pt; margin: 0 0 1mm 0; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list thead { display: table-header-group; }
        table.list th { text-align: left; font-size: 7pt; text-transform: uppercase; color: #555; border-bottom: 0.6pt solid #999; padding: 1.5mm; }
        table.list td { border-bottom: 0.4pt solid #ddd; padding: 1.5mm; vertical-align: top; }
        table.list tr { page-break-inside: avoid; }
        .check { width: 3.5mm; height: 3.5mm; border: 0.6pt solid #333; display: inline-block; }
        .box-row .name { font-weight: bold; }
        .tag { font-size: 6.5pt; border: 0.4pt solid #888; border-radius: 1mm; padding: 0 1mm; color: #333; }
        .text { font-size: 7.5pt; color: #444; margin-top: 0.8mm; }
    </style>
</head>
<body>
    {{-- letterhead: print logo and the organization; the address stands only in the address field --}}
    {{-- the letterhead ends above the address field (45 mm on the page) --}}
    <div class="abs" style="top: 0; left: 25mm; width: 95mm; height: 28mm; overflow: hidden;">
        <table style="border-collapse: collapse;">
            <tr>
                @if ($logo)
                    <td style="padding-right: 4mm; vertical-align: top;"><img src="{{ $logo }}" style="max-width: 24mm; max-height: 24mm;" alt=""></td>
                @endif
                <td style="vertical-align: top;">
                    <div style="font-size: 11pt; font-weight: bold;">{{ $organization }}</div>
                    @if ($contact)
                        <div class="muted" style="font-size: 8pt;">{{ $contact }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    @if ($letter)
        {{-- fold marks and hole mark (only the first page, absolute on it) --}}
        <div class="mark" style="top: 90mm;"></div>
        <div class="mark" style="top: 133.5mm; width: 8mm;"></div>
        <div class="mark" style="top: 195mm;"></div>

        {{-- address field of a window envelope: return line (sender), empty note zone, recipient --}}
        <div class="abs" style="top: 30mm; left: 20mm; width: 80mm; height: 45mm; padding-left: 5mm;">
            {{-- the controller shortens and sizes the return line so it fits the 5 mm zone --}}
            <div style="height: 5mm; font-size: {{ $returnLineSize }}pt; line-height: 1.1; color: #555; border-bottom: 0.3pt solid #999; overflow: hidden;">
                {{ $returnLine }}
            </div>
            <div style="height: 12.7mm;"></div>
            {{-- the controller picks a size at which every line fits the zone without wrapping --}}
            <div style="font-size: {{ $addressSize }}pt; line-height: 1.2; white-space: nowrap;">
                @foreach ($recipient as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- information block with the qr code of the short link; in a letter beside the address field.
         It ends at the right margin of the text (20 mm), like the table below. --}}
    <div class="abs" style="top: {{ $letter ? 35 : 0 }}mm; left: 125mm; width: 65mm;">
        <table style="border-collapse: collapse; width: 100%;">
            <tr>
                <td style="vertical-align: top;">
                    <div class="muted" style="font-size: 7pt;">Box</div>
                    <div style="font-weight: bold;">{{ $box->name }}</div>
                    <div class="mono">{{ $box->number }}</div>
                    <div class="muted" style="font-size: 7pt; margin-top: 2mm;">Stand</div>
                    <div>{{ now()->format('d.m.Y') }}</div>
                    <div class="muted" style="font-size: 7pt; margin-top: 2mm;">Inhalt</div>
                    <div>{{ count($rows) }} Gegenstände</div>
                </td>
                <td style="vertical-align: top; width: 28mm; text-align: center;">
                    <img src="{{ $qr }}" style="width: 26mm; height: 26mm;" alt="">
                    <div style="font-size: 6.5pt; color: #444;">Scannen: Inhaltsliste digital öffnen und abhaken</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="page-footer">
        {{ collect([$organization, $contact])->filter()->implode(' · ') }} · Inhaltsliste {{ $box->number }}
    </div>

    <div class="content" style="padding-top: {{ $letter ? 88 : 40 }}mm;">
        <table style="border-collapse: collapse; margin-bottom: 4mm;">
            <tr>
                @if ($boxPhoto)
                    <td style="padding-right: 4mm; vertical-align: top;"><img src="{{ $boxPhoto }}" style="max-width: 30mm; max-height: 30mm;" alt=""></td>
                @endif
                <td style="vertical-align: top;">
                    <h1>Inhaltsliste {{ $box->name }}</h1>
                    <p class="muted" style="margin: 0;">Beim Packen und Auspacken abhaken. Boxen in der Box sind eingerückt mit ihrem Inhalt.</p>
                </td>
            </tr>
        </table>

        @if (count($rows))
            <table class="list">
                <thead>
                    <tr>
                        <th style="width: 6mm;"></th>
                        @if ($withPhotos)
                            <th style="width: 20mm;">Foto</th>
                        @endif
                        <th>Gegenstand</th>
                        <th style="width: 30mm;">Nummer</th>
                        <th style="width: 22mm; text-align: right;">Menge</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($item = $row['item'])
                        <tr class="{{ $item->is_container ? 'box-row' : '' }}">
                            <td><span class="check"></span></td>
                            @if ($withPhotos)
                                <td>@if ($row['photo'])<img src="{{ $row['photo'] }}" style="max-width: 18mm; max-height: 18mm;" alt="">@endif</td>
                            @endif
                            <td style="padding-left: {{ 1.5 + $row['depth'] * 5 }}mm;">
                                <span class="name">{{ $item->name }}</span>
                                @if ($item->is_container) <span class="tag">Box</span>@endif
                                @if ($item->kind->value === 'bulk' && in_array($item->unit, $consumableUnits, true)) <span class="tag">Verbrauchsmaterial</span>@endif
                                @if ($item->description)
                                    <div class="text">{!! nl2br(e($item->description)) !!}</div>
                                @endif
                                @if ($item->notes)
                                    <div class="text"><em>Notiz:</em> {!! nl2br(e($item->notes)) !!}</div>
                                @endif
                            </td>
                            <td class="mono" style="font-size: 8pt;">{{ $item->number }}</td>
                            <td style="text-align: right;">{{ $item->kind->value === 'bulk' ? trim($item->quantity.' '.$item->unit) : '1' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="muted">Die Box ist leer.</p>
        @endif
    </div>
</body>
</html>

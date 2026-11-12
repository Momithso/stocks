{{-- Signatures of a document: images from the screen, or empty lines to sign on paper. Role above the line,
     name and time below it, each on one line. --}}
<table class="signatures">
    <tr>
        @foreach ($roles as $role => $label)
            @php($signature = collect($signatures)->firstWhere('role', $label))
            <td>
                <div class="signature-image">
                    @if ($signature && $signature['image'])
                        <img src="{{ $signature['image'] }}" style="max-height: 18mm; max-width: 70mm;" alt="">
                    @endif
                </div>
                <div class="signature-line nowrap">{{ $label }}</div>
                @if ($signature)
                    <div class="nowrap" style="font-size: 7.5pt;">{{ $signature['name'] }}</div>
                    <div class="nowrap muted" style="font-size: 7pt;">{{ \Illuminate\Support\Carbon::parse($signature['signed_at'])->format('d.m.Y, H:i') }} Uhr{{ $signature['on_paper'] ? ' · auf Papier' : '' }}</div>
                @endif
            </td>
        @endforeach
    </tr>
</table>

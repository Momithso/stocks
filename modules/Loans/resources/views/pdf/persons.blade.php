{{-- Persons and time of a loan. --}}
<table class="persons">
    <tr>
        @foreach (['borrower' => 'Entleiher', 'responsible' => 'Verantwortliche Person'] as $key => $label)
            @if (! empty($doc[$key]))
                <td>
                    <div class="muted" style="font-size: 7pt;">{{ $label }}</div>
                    <strong>{{ $doc[$key]['name'] }}</strong>
                    @foreach (['affiliation', 'address', 'phone', 'email'] as $field)
                        @if (! empty($doc[$key][$field]))<div>{{ $doc[$key][$field] }}</div>@endif
                    @endforeach
                </td>
            @endif
        @endforeach
        <td>
            <div class="muted" style="font-size: 7pt;">Zeitraum</div>
            <div class="nowrap">Abholung {{ $doc['pickup'] }} Uhr</div>
            <div class="nowrap">Rückgabe {{ $doc['return'] }} Uhr</div>
            @if (! empty($doc['purpose']))<div>Anlass: {{ $doc['purpose'] }}</div>@endif
            @if (! empty($doc['event']))<div>Veranstaltung: {{ $doc['event'] }}</div>@endif
        </td>
    </tr>
</table>

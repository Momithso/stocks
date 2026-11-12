{{-- Material list: positions with the contents of boxes indented, condition and a box to tick. --}}
<table class="data">
    <thead>
        <tr><th style="width: 6mm;"></th><th>Nummer</th><th>Gegenstand</th><th>Menge</th><th>Zustand</th></tr>
    </thead>
    <tbody>
        @foreach ($positions as $row)
            <tr>
                <td><span class="check"></span></td>
                <td class="mono nowrap" style="padding-left: {{ 1.2 + $row['depth'] * 5 }}mm;">{{ $row['number'] }}</td>
                <td style="{{ $row['depth'] === 0 ? 'font-weight: bold;' : '' }}">
                    {{ $row['name'] }}
                    @if ($row['note'])
                        <div class="muted" style="font-size: 7pt; font-weight: normal;">{{ $row['note'] }}</div>
                    @endif
                </td>
                <td class="nowrap">{{ $row['bulk'] ? $row['quantity'].' '.$row['unit'] : '1' }}</td>
                <td>{{ $row['condition'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

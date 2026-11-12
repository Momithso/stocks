@extends('loans::pdf.layout')

@section('title', 'Rückgabebeleg')

@section('content')
    <h1>Rückgabebeleg</h1>
    <p>Rückgabe durch <strong>{{ $doc['returner'] }}</strong>, angenommen von <strong>{{ $doc['receiver'] }}</strong> am {{ $doc['date'] }} Uhr.</p>
    @if ($doc['unchecked'])
        <div class="stamp">Ungeprüft angenommen, Mängel vorbehalten</div>
    @endif

    <table class="data">
        <thead>
            <tr><th>Nummer</th><th>Gegenstand</th><th>Menge</th><th>Stand</th><th>Vermerk</th></tr>
        </thead>
        <tbody>
            @foreach ($doc['rows'] as $row)
                <tr>
                    <td class="mono nowrap" style="padding-left: {{ 1.2 + $row['depth'] * 5 }}mm;">{{ $row['number'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['quantity'] !== null ? $row['quantity'].' von '.$row['of'].' '.$row['unit'] : '1' }}</td>
                    <td>{{ $row['state'] }}</td>
                    <td>{{ $row['note'] }}@if ($row['manual']) <span class="muted">(von Hand abgehakt)</span>@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($doc['kind'] !== 'internal')
        @include('loans::pdf.signatures', ['roles' => ['returner' => 'Rückgabe durch', 'receiver' => 'Angenommen von']])
    @endif
@endsection

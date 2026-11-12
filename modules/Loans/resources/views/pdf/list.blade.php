@extends('loans::pdf.layout')

@section('title', ($doc['supplement'] ?? false) ? 'Materialliste Nachtrag' : 'Materialliste')

@section('content')
    <h1>Materialliste{{ ($doc['supplement'] ?? false) ? ' – Nachtrag' : '' }}</h1>
    @if ($doc['supplement'] ?? false)
        <p class="muted">Nachträglich ausgegebener Rest der Buchung; es gilt der bei der ersten Ausgabe unterschriebene Leihvertrag.</p>
    @endif
    @include('loans::pdf.persons')
    @include('loans::pdf.positions', ['positions' => $doc['positions']])

    @if ($doc['kind'] === 'internal')
        <p class="muted" style="margin-top: 4mm;">Eigenbedarf, verantwortlich: {{ $doc['responsible']['name'] ?? '–' }}. Ausgegeben von {{ $doc['issuer'] ?? '–' }} am {{ $doc['date'] }}.</p>
    @else
        <p style="margin-top: 4mm;">Mit der Unterschrift bestätigt die verantwortliche Person, die Gegenstände vollständig und im genannten Zustand erhalten zu haben.</p>
        @include('loans::pdf.signatures', ['roles' => ['responsible' => 'Verantwortliche Person', 'issuer' => 'Für den Verleiher']])
    @endif
@endsection

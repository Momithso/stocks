@extends('loans::pdf.layout')

@section('title', 'Buchungsbestätigung')

@section('content')
    <h1>Buchungsbestätigung</h1>
    @include('loans::pdf.persons')

    @if ($doc['pin'])
        <p style="margin-top: 3mm;">Pack-PIN: <strong class="mono" style="font-size: 12pt;">{{ $doc['pin'] }}</strong></p>
        <p class="muted" style="font-size: 7.5pt;">Mit der Pack-PIN lässt sich auf der Seite einer Box (QR-Code auf dem Etikett) bestätigen, dass die Box kontrolliert wurde. Sie gilt nur für die Boxen dieser Buchung.</p>
    @endif

    <h2>Gebucht</h2>
    @include('loans::pdf.positions', ['positions' => $doc['positions']])
    <p class="muted" style="margin-top: 3mm; font-size: 7.5pt;">Stand {{ $doc['date'] }}. Die Gegenstände sind für den Zeitraum reserviert. Bei der Abholung werden sie gescannt und Leihvertrag und Materialliste unterschrieben.</p>
@endsection

@extends('loans::pdf.layout')

@section('title', 'Leihvertrag')

@section('content')
    {{-- the contract text is html made by ContractTemplate::render from escaped text --}}
    {!! $doc['contract'] !!}

    @include('loans::pdf.signatures', ['roles' => ['responsible' => 'Verantwortliche Person', 'issuer' => 'Für den Verleiher']])

    <p class="muted" style="margin-top: 4mm; font-size: 7.5pt;">Anlage: Materialliste {{ $doc['number'] }}</p>
@endsection

@extends('layouts.app')

@section('title', 'People')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3">People</h1>
    <span class="text-muted small">{{ $people->total() }} person(s)</span>
</div>

<form method="GET" action="{{ route('people.index') }}" class="mb-4">
    <div class="input-group">
        <input type="search" name="q" class="form-control"
               placeholder="Search by name, occupation, expedition…"
               value="{{ request('q') }}" autofocus>
        @if(request('q'))
            <a href="{{ route('people.index') }}" class="btn btn-outline-secondary">✕</a>
        @else
            <button type="submit" class="btn btn-outline-secondary">Search</button>
        @endif
    </div>
</form>

<div class="row g-3">
    @foreach($people as $person)
    <div class="col-6 col-sm-4 col-md-3">
        <div class="card h-100 text-center p-3">
            @if($person->image)
                <img src="{{ $person->image }}"
                     class="rounded-circle mx-auto mb-2"
                     style="width:72px;height:72px;object-fit:cover;"
                     onerror="this.style.display='none'">
            @else
                <div class="rounded-circle bg-secondary mx-auto mb-2 d-flex align-items-center justify-content-center"
                     style="width:72px;height:72px;">
                    <span class="text-white fs-4">👤</span>
                </div>
            @endif
            <h6 class="card-title mb-1" style="font-size:clamp(0.8rem,2.5vw,0.95rem);">
                {{ $person->name ?? $person->wikidata_qid }}
            </h6>
            @if($person->description)
                <p class="small text-muted mb-1 d-none d-sm-block">{{ Str::limit($person->description, 60) }}</p>
            @endif
            @if($person->date_birth)
                <p class="small text-muted mb-2">
                    {{ $person->date_birth }}
                    @if($person->date_death) – {{ $person->date_death }} @endif
                </p>
            @endif
            <div class="d-flex justify-content-center gap-1 flex-wrap mb-2">
                @if($person->expeditions_count)
                    <span class="badge bg-light text-muted border" style="font-size:10px;">
                        {{ $person->expeditions_count }} exp.
                    </span>
                @endif
                @if($person->specimens_count)
                    <span class="badge bg-light text-muted border" style="font-size:10px;">
                        {{ $person->specimens_count }} spec.
                    </span>
                @endif
            </div>
            <div class="d-flex justify-content-center gap-1 mt-auto">
                @if($person->wikipedia_url)
                <button class="wiki-btn btn btn-sm btn-outline-secondary"
                        data-qid="{{ $person->wikidata_qid }}"
                        data-label="{{ $person->name ?? $person->wikidata_qid }}"
                        data-wp-url="{{ $person->wikipedia_url }}"
                        title="Wikipedia">
                    <i class="bi bi-wikipedia"></i>
                </button>
                @endif
                <a href="{{ route('people.show', $person->wikidata_qid) }}"
                   class="btn btn-sm btn-outline-primary">View</a>
            </div>
        </div>
    </div>
    @endforeach
</div>

<div class="mt-4">{{ $people->links() }}</div>

@include('partials.wiki-modal')
@endsection
@extends('layouts.app')

@section('title', 'Institutions')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h2 mb-1">Institutions</h1>
        <p class="text-muted mb-0">Natural history collections represented in expedition specimens</p>
    </div>
</div>

{{-- Search --}}
<form method="GET" action="{{ route('institutions.index') }}" class="mb-4">
    <div class="input-group" style="max-width: 440px;">
        <input type="text" name="q" class="form-control"
               placeholder="Search by name, code or country…"
               value="{{ $q }}">
        <button class="btn btn-outline-secondary" type="submit">Search</button>
        @if($q)
        <a href="{{ route('institutions.index') }}" class="btn btn-outline-secondary">✕</a>
        @endif
    </div>
</form>

<div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3 mb-4">
    @forelse($institutions as $inst)
    <div class="col">
        <a href="{{ route('institutions.show', urlencode($inst->code)) }}"
           class="card h-100 text-decoration-none text-dark shadow-sm" style="border-radius:8px;">
            <div class="card-body d-flex flex-column gap-1">

                {{-- Code badge + name --}}
                <div>
                    <span class="badge bg-secondary fw-normal font-monospace mb-1">{{ $inst->code }}</span>
                    @if($inst->name)
                    <h6 class="card-title fw-bold mb-0 lh-sm">{{ $inst->name }}</h6>
                    @else
                    <h6 class="card-title fw-bold mb-0 text-muted fst-italic">Unknown institution</h6>
                    @endif
                </div>

                {{-- Location --}}
                @if($inst->city || $inst->country)
                <div class="text-muted small">
                    📍
                    @if($inst->city && $inst->country)
                        {{ $inst->city }}, {{ $inst->country }}
                    @else
                        {{ $inst->city ?? $inst->country }}
                    @endif
                </div>
                @endif

                {{-- Homepage --}}
                @if($inst->homepage)
                <div class="text-muted small text-truncate" title="{{ $inst->homepage }}">
                    🌐 {{ preg_replace('#^https?://(www\.)?#', '', rtrim($inst->homepage, '/')) }}
                </div>
                @endif

                {{-- Stats --}}
                <div class="d-flex flex-wrap gap-3 text-muted small mt-auto pt-2 border-top">
                    <span title="Specimens">🔬 {{ number_format($inst->specimens_count) }} specimens</span>
                    <span title="Expeditions">🗺 {{ $inst->expeditions_count }} {{ Str::plural('expedition', $inst->expeditions_count) }}</span>
                    @if($inst->images_count > 0)
                    <span title="With images">🖼 {{ number_format($inst->images_count) }}</span>
                    @endif
                </div>

            </div>
        </a>
    </div>
    @empty
    <div class="col-12">
        @if($q)
        <p class="text-muted">No institutions found for "<strong>{{ $q }}</strong>".</p>
        @else
        <p class="text-muted">No institutions found. Run <code>/sync/institutions</code> to populate.</p>
        @endif
    </div>
    @endforelse
</div>

{{ $institutions->links() }}

@endsection
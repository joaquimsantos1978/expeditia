@extends('layouts.app')

@section('title', 'Expeditions')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3">Expeditions</h1>
    <span class="text-muted">{{ $expeditions->total() }} expedition(s)</span>
</div>

<div class="d-flex gap-2 mb-4">
    <form method="GET" action="{{ route('expeditions.index') }}" class="flex-grow-1">
        <div class="input-group">
            <input type="search" name="q" class="form-control"
                   placeholder="Search by name, participant, country…"
                   value="{{ request('q') }}" autofocus>
            @if(request('q'))
                <a href="{{ route('expeditions.index') }}" class="btn btn-outline-secondary">✕ Clear</a>
            @else
                <button type="submit" class="btn btn-outline-secondary">Search</button>
            @endif
        </div>
    </form>
    <button class="btn btn-outline-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#addExpeditionModal">
        + Add QID
    </button>
</div>

{{-- Add Expedition Modal --}}
<div class="modal fade" id="addExpeditionModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Add expedition from Wikidata</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Enter a Wikidata QID (e.g. <code>Q12345</code>) to import an expedition.</p>
                <div class="input-group">
                    <input type="text" id="addQidInput" class="form-control"
                           placeholder="Q12345" pattern="Q\d+" title="Must be a valid QID like Q12345">
                    <button class="btn btn-primary" id="addQidBtn" onclick="addExpedition()">Add</button>
                </div>
                <div id="addQidStatus" class="mt-2 small"></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    @foreach($expeditions as $expedition)
    <div class="col-md-4">
        <div class="card h-100">
            @if($expedition->image)
                <img src="{{ $expedition->image }}"
                     class="expedition-image"
                     alt="{{ $expedition->label }}"
                     onerror="this.style.display='none'">
            @else
                <div class="expedition-image bg-secondary d-flex align-items-center justify-content-center">
                    <span class="text-white fs-1">🧭</span>
                </div>
            @endif
            <div class="card-body">
                <h5 class="card-title">{{ $expedition->label ?? $expedition->wikidata_qid }}</h5>
                @if($expedition->description)
                    <p class="card-text text-muted small">{{ Str::limit($expedition->description, 100) }}</p>
                @endif
                <p class="card-text small">
                    @if($expedition->start_time)
                        📅 {{ $expedition->start_time }}
                        @if($expedition->end_time) – {{ $expedition->end_time }} @endif
                    @endif
                    @if($expedition->country)
                        <br>🌍 {{ explode('||', $expedition->country)[0] }}
                    @endif
                </p>
            </div>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    {{ $expedition->people_count }} participant(s) ·
                    {{ $expedition->map_items_count }} items
                </small>
                <div class="d-flex gap-2">
                    @if($expedition->wikipedia_url)
                    <button class="wiki-btn btn btn-sm btn-outline-secondary"
                            data-qid="{{ $expedition->wikidata_qid }}"
                            data-label="{{ $expedition->label }}"
                            data-wp-url="{{ $expedition->wikipedia_url }}">
<i class="bi bi-wikipedia"></i>
                    </button>
                    @endif
                    <a href="{{ route('expeditions.show', $expedition->wikidata_qid) }}"
                       class="btn btn-sm btn-primary">View →</a>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

<div class="mt-4">{{ $expeditions->links() }}</div>

{{-- Wikipedia modal --}}
@include('partials.wiki-modal')

@include('partials.sync-panel')
@endsection

@section('scripts')
<script>
function addExpedition() {
    const input  = document.getElementById('addQidInput');
    const status = document.getElementById('addQidStatus');
    const btn    = document.getElementById('addQidBtn');
    const qid    = input.value.trim().toUpperCase();

    if (!/^Q\d+$/.test(qid)) {
        status.innerHTML = '<span class="text-danger">Invalid QID — must look like Q12345.</span>';
        return;
    }

    status.innerHTML = '<span class="text-muted">Checking Wikidata…</span>';
    btn.disabled = true;

    fetch(`{{ url('/sync/add') }}/${qid}`)
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            if (data.status === 'error') {
                status.innerHTML = `<span class="text-danger">❌ ${data.message}</span>`;
            } else {
                const syncUrl = `{{ url('/sync/resync') }}/${data.qid}`;
                const expUrl  = `{{ url('/expedition') }}/${data.qid}`;
                const addModal = document.getElementById('addExpeditionModal');
                addModal.addEventListener('hidden.bs.modal', function handler() {
                    addModal.removeEventListener('hidden.bs.modal', handler);
                    startSync(syncUrl, null, expUrl);
                });
                bootstrap.Modal.getInstance(addModal).hide();
            }
        })
        .catch(err => {
            btn.disabled = false;
            status.innerHTML = `<span class="text-danger">❌ ${err.message}</span>`;
        });
}

    document.getElementById('addExpeditionModal')?.addEventListener('hidden.bs.modal', function () {
    document.getElementById('addQidInput').value = '';
    document.getElementById('addQidStatus').innerHTML = '';
    document.getElementById('addQidBtn').disabled = false;
});

document.getElementById('addQidInput')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') addExpedition();
});
</script>
@endsection

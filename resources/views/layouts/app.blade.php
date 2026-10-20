<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expeditia · @yield('title', 'Home')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .navbar-brand { font-weight: bold; letter-spacing: 1px; }
        .card { border: none; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .expedition-image { width:100%; height:220px; object-fit:cover; border-radius:4px 4px 0 0; }
        #map { height:500px; width:100%; border-radius:4px; }
        .timeline-dot { width:12px; height:12px; border-radius:50%; background:#0d6efd; display:inline-block; margin-right:8px; }
        .timeline-dot.specimen { background:#198754; }
        @media (max-width:576px) {
            #map { height:300px; }
            .container { padding-left:12px; padding-right:12px; }
        }
    </style>
    @yield('styles')
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="{{ route('expeditions.index') }}">🧭 Expeditia</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="{{ route('expeditions.index') }}">Expeditions</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('people.index') }}">People</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('institutions.index') }}">Institutions</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
    @yield('content')
</div>

<footer class="mt-5 border-top pt-4 pb-5">
    <div class="container">
        {{-- Powered-by logos --}}
        <div class="d-flex flex-wrap justify-content-center align-items-center gap-4 mb-3">
            <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.06em;">Powered by</span>
            <a href="https://www.wikidata.org" target="_blank" rel="noopener" title="Wikidata">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/4/41/Wikidata_Stamp_Rec_Light.svg/120px-Wikidata_Stamp_Rec_Light.svg.png"
                     alt="Powered by Wikidata" height="32" style="opacity:.85;">
            </a>
            <a href="https://www.gbif.org" target="_blank" rel="noopener" title="GBIF">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/3/3a/Gbif-full-green-logo.svg/120px-Gbif-full-green-logo.svg.png"
                     alt="Powered by GBIF" height="28" style="opacity:.85;">
            </a>
            <a href="https://bionomia.net" target="_blank" rel="noopener" title="Bionomia">
                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/e/e4/Logo_for_Bionomia.png/120px-Logo_for_Bionomia.png"
                     alt="Powered by Bionomia" height="28" style="opacity:.85;">
            </a>
            <a href="https://georeference.it" target="_blank" rel="noopener" title="georeference.it">
                <img src="https://georeference.it/images/logo.png"
                     alt="Powered by georeference.it" height="28" style="opacity:.85;">
            </a>
        </div>
        {{-- Links --}}
        <p class="text-center text-muted mb-0" style="font-size:.8rem;">
            <a href="{{ route('about') }}" class="text-muted">About</a>
            &nbsp;·&nbsp;
            <a href="{{ route('about.developers') }}" class="text-muted">For developers</a>
            &nbsp;·&nbsp;
            <a href="https://github.com/joaquimsantos1978/expeditia" target="_blank" rel="noopener" class="text-muted"><i class="bi bi-github"></i> Source code</a>
        </p>
    </div>
</footer>

@yield('scripts')
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
{{--
  Reusable Wikipedia modal partial.
  Usage: @include('partials.wiki-modal')
  Trigger: <button class="wiki-btn" data-qid="Q123" data-label="Name">...</button>
--}}

{{-- Wikipedia icon SVG (public domain, Wikipedia globe) --}}
@once
<style>
.wiki-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
}
.wiki-btn svg { flex-shrink: 0; }
</style>
@endonce

{{-- Modal --}}
<div class="modal fade" id="wikiModal" tabindex="-1" aria-labelledby="wikiModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <div class="d-flex align-items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 50 50">
                        <circle cx="25" cy="25" r="24" fill="#fff" stroke="#ccc" stroke-width="1"/>
                        <text x="25" y="33" font-family="Linux Libertine,Georgia,serif" font-size="28"
                              font-weight="bold" fill="#000" text-anchor="middle">W</text>
                    </svg>
                    <h6 class="modal-title mb-0" id="wikiModalTitle">Wikipedia</h6>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="wikiModalBody">
                <div class="text-center text-muted py-4">Loading…</div>
            </div>
            <div class="modal-footer py-2 justify-content-between">
                <div id="wikiModalLinks" class="d-flex gap-2 flex-wrap"></div>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    if (window._wikiModalInit) return; // run once even if partial included multiple times
    window._wikiModalInit = true;

    const cache = {};
    let   wikiModal = null;

    function getModal() {
        if (!wikiModal) wikiModal = new bootstrap.Modal(document.getElementById('wikiModal'));
        return wikiModal;
    }

    async function loadWiki(qid, label, knownWpUrl) {
        if (cache[qid]) return cache[qid];

        // If we already have the Wikipedia URL (stored during sync), skip Wikidata
        let wpTitle = null;
        const wdUrl = `https://www.wikidata.org/wiki/${qid}`;

        if (knownWpUrl) {
            wpTitle = decodeURIComponent(knownWpUrl.split('/wiki/')[1] ?? '').replace(/_/g, ' ') || null;
        } else {
            try {
                const wd = await fetch(
                    'https://www.wikidata.org/w/api.php?' + new URLSearchParams({
                        action: 'wbgetentities', ids: qid,
                        props: 'sitelinks', sitefilter: 'enwiki',
                        format: 'json', origin: '*',
                    })
                ).then(r => r.json());
                wpTitle = wd.entities?.[qid]?.sitelinks?.enwiki?.title ?? null;
            } catch {}
        }

        // 2. Wikipedia summary API
        if (wpTitle) {
            try {
                const wp = await fetch(
                    `https://en.wikipedia.org/api/rest_v1/page/summary/${encodeURIComponent(wpTitle)}`
                ).then(r => r.json());
                cache[qid] = {
                    title:   wp.title ?? label,
                    extract: wp.extract ?? '',
                    image:   wp.originalimage?.source ?? wp.thumbnail?.source ?? null,
                    wpUrl:   wp.content_urls?.desktop?.page ?? null,
                    wdUrl,
                };
                return cache[qid];
            } catch {}
        }

        cache[qid] = { title: label, extract: null, image: null, wpUrl: null, wdUrl };
        return cache[qid];
    }

    function renderModal(data, label) {
        document.getElementById('wikiModalTitle').textContent = data.title ?? label;

        if (!data.wpUrl && !data.extract) {
            document.getElementById('wikiModalBody').innerHTML =
                '<p class="text-muted py-2">No Wikipedia article found for this entry.</p>';
        } else {
            const img = data.image
                ? `<img src="${data.image}" style="width:180px;max-height:240px;object-fit:cover;border-radius:4px;flex-shrink:0;" alt="" onerror="this.remove()">`
                : '';
            document.getElementById('wikiModalBody').innerHTML = `
                <div class="d-flex gap-4 align-items-start">
                    ${img}
                    <p style="line-height:1.75;margin:0">${data.extract ?? ''}</p>
                </div>`;
        }

        const links = document.getElementById('wikiModalLinks');
        links.innerHTML = '';
        if (data.wpUrl) {
            links.innerHTML += `<a href="${data.wpUrl}" target="_blank" class="btn btn-sm btn-outline-primary"><img src=\"https://en.wikipedia.org/favicon.ico\" width=\"14\" height=\"14\" style=\"vertical-align:text-bottom;margin-right:3px;\" alt=\"\"> Read on Wikipedia ↗</a>`;
        }
        links.innerHTML += `<a href="${data.wdUrl}" target="_blank" class="btn btn-sm btn-outline-secondary"><img src=\"https://www.wikidata.org/static/favicon/wikidata.ico\" width=\"14\" height=\"14\" style=\"vertical-align:text-bottom;margin-right:3px;\" alt=\"\"> Wikidata ↗</a>`;
    }

    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('.wiki-btn');
        if (!btn) return;

        const qid      = btn.dataset.qid;
        const label    = btn.dataset.label;
        const wpUrl    = btn.dataset.wpUrl || null;

        // Reset modal
        document.getElementById('wikiModalTitle').textContent = label;
        document.getElementById('wikiModalBody').innerHTML = '<div class="text-center text-muted py-4">Loading…</div>';
        document.getElementById('wikiModalLinks').innerHTML = '';
        getModal().show();

        const data = await loadWiki(qid, label, wpUrl);
        renderModal(data, label);
    });
})();
</script>

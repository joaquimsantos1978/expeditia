<style>
.wd-entity {
    color: #0645ad;
    cursor: pointer;
    text-decoration: underline;
    text-decoration-style: dotted;
    text-underline-offset: 2px;
}
.wd-entity:hover { text-decoration-style: solid; }

#wd-hover-card {
    position: fixed;
    z-index: 9999;
    width: 320px;
    background: #fff;
    border: 1px solid #d0d0d0;
    border-radius: 4px;
    box-shadow: 0 4px 20px rgba(0,0,0,.18);
    font-size: 13px;
    line-height: 1.5;
    pointer-events: auto;
    opacity: 0;
    transition: opacity .15s ease;
}
#wd-hover-card.visible { opacity: 1; }
#wd-hover-card .wdhc-body {
    display: flex;
    gap: 10px;
    padding: 14px;
}
#wd-hover-card .wdhc-text { flex: 1; min-width: 0; }
#wd-hover-card .wdhc-title {
    font-weight: bold;
    font-size: 14px;
    margin-bottom: 4px;
}
#wd-hover-card .wdhc-desc {
    color: #444;
    display: -webkit-box;
    -webkit-line-clamp: 6;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
#wd-hover-card .wdhc-img {
    width: 80px;
    height: 90px;
    object-fit: cover;
    flex-shrink: 0;
    border-radius: 3px;
}
#wd-hover-card .wdhc-footer {
    border-top: 1px solid #eee;
    padding: 6px 14px;
    display: flex;
    gap: 10px;
}
#wd-hover-card .wdhc-footer a {
    font-size: 12px;
    color: #0645ad;
    text-decoration: none;
}
#wd-hover-card .wdhc-footer a:hover { text-decoration: underline; }
#wd-hover-card .wdhc-loading {
    padding: 14px;
    color: #888;
    font-size: 12px;
}
</style>

<div id="wd-hover-card" role="tooltip" aria-live="polite">
    <div class="wdhc-loading">Loading…</div>
</div>

<script>
(function () {
    const card  = document.getElementById('wd-hover-card');
    const cache = {};
    let showTimer = null, hideTimer = null, activeEl = null;

    async function loadEntity(qid, label) {
        if (cache[qid]) return cache[qid];

        // 1. Wikidata action API — reliable CORS support via origin=*
        let wpTitle = null, wdDesc = '';
        try {
            const url = 'https://www.wikidata.org/w/api.php?' + new URLSearchParams({
                action: 'wbgetentities',
                ids: qid,
                props: 'descriptions|sitelinks',
                languages: 'en',
                sitefilter: 'enwiki',
                format: 'json',
                origin: '*',
            });
            const wd  = await fetch(url).then(r => r.json());
            const ent = wd.entities?.[qid];
            wpTitle   = ent?.sitelinks?.enwiki?.title ?? null;
            wdDesc    = ent?.descriptions?.en?.value  ?? '';
        } catch (err) { console.warn('Wikidata API error', err); }

        // 2. Wikipedia summary API — gives extract + thumbnail in one call
        if (wpTitle) {
            try {
                const wp = await fetch(
                    `https://en.wikipedia.org/api/rest_v1/page/summary/${encodeURIComponent(wpTitle)}`
                ).then(r => r.json());
                cache[qid] = {
                    title:   wp.title ?? label,
                    extract: wp.extract ?? wdDesc,
                    image:   wp.thumbnail?.source ?? null,
                    wdUrl:   `https://www.wikidata.org/wiki/${qid}`,
                    wpUrl:   wp.content_urls?.desktop?.page ?? null,
                };
                return cache[qid];
            } catch (err) { console.warn('Wikipedia API error', err); }
        }

        // 3. Fallback — Wikidata description only
        cache[qid] = {
            title:   label,
            extract: wdDesc,
            image:   null,
            wdUrl:   `https://www.wikidata.org/wiki/${qid}`,
            wpUrl:   null,
        };
        return cache[qid];
    }

    function renderCard(data) {
        const img = data.image
            ? `<img class="wdhc-img" src="${data.image}" alt="" onerror="this.remove()">` : '';
        const wpLink = data.wpUrl
            ? `<a href="${data.wpUrl}" target="_blank"><img src=\"https://en.wikipedia.org/favicon.ico\" width=\"14\" height=\"14\" style=\"vertical-align:text-bottom;margin-right:3px;\" alt=\"\"> Wikipedia ↗</a>` : '';
        card.innerHTML = `
            <div class="wdhc-body">
                <div class="wdhc-text">
                    <div class="wdhc-title">${data.title}</div>
                    <div class="wdhc-desc">${data.extract || '<em style="color:#aaa">No description available.</em>'}</div>
                </div>
                ${img}
            </div>
            <div class="wdhc-footer">
                <a href="${data.wdUrl}" target="_blank"><img src=\"https://www.wikidata.org/static/favicon/wikidata.ico\" width=\"14\" height=\"14\" style=\"vertical-align:text-bottom;margin-right:3px;\" alt=\"\"> Wikidata ↗</a>
                ${wpLink}
            </div>`;
    }

    function positionCard(x, y) {
        const margin = 12;
        const vw = window.innerWidth, vh = window.innerHeight;
        const cw = card.offsetWidth || 320, ch = card.offsetHeight || 160;
        let left = x + margin;
        if (left + cw > vw - 8) left = x - cw - margin;
        let top = y + margin;
        if (top + ch > vh - 8) top = y - ch - margin;
        card.style.left = Math.max(8, left) + 'px';
        card.style.top  = Math.max(8, top)  + 'px';
    }

    function showCard(el, x, y) {
        activeEl = el;
        card.innerHTML = '<div class="wdhc-loading">Loading…</div>';
        positionCard(x, y);
        card.classList.add('visible');
        const qid = el.dataset.qid, label = el.dataset.label;
        if (cache[qid]) {
            renderCard(cache[qid]);
            positionCard(x, y);
        } else {
            loadEntity(qid, label).then(data => {
                if (activeEl !== el) return;
                if (data) renderCard(data);
                positionCard(x, y);
            });
        }
    }

    function hideCard() {
        card.classList.remove('visible');
        activeEl = null;
    }

    document.addEventListener('mouseover', function (e) {
        const el = e.target.closest('.wd-entity');
        if (!el) return;
        clearTimeout(hideTimer);
        clearTimeout(showTimer);
        showTimer = setTimeout(() => showCard(el, e.clientX, e.clientY), 350);
    });

    document.addEventListener('click', function (e) {
        const el = e.target.closest('.wd-entity');
        if (!el) return;
        const qid   = el.dataset.qid;
        const label = el.dataset.label;
        const data  = cache[qid];
        const url   = data?.wpUrl ?? `https://www.wikidata.org/wiki/${qid}`;
        window.open(url, '_blank');
    });

    document.addEventListener('mousemove', function (e) {
        if (!card.classList.contains('visible')) return;
        if (!e.target.closest('.wd-entity') && !e.target.closest('#wd-hover-card')) {
            clearTimeout(hideTimer);
            hideTimer = setTimeout(hideCard, 200);
        }
    });

    document.addEventListener('mouseout', function (e) {
        if (!e.target.closest('.wd-entity')) return;
        clearTimeout(showTimer);
        hideTimer = setTimeout(hideCard, 300);
    });

    card.addEventListener('mouseenter', () => clearTimeout(hideTimer));
    card.addEventListener('mouseleave', () => { hideTimer = setTimeout(hideCard, 200); });
})();
</script>

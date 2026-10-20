{{-- resources/views/partials/sync-panel.blade.php --}}
{{-- Include once per page: @include('partials.sync-panel') --}}

<!-- Sync Modal -->
<div class="modal fade" id="syncModal" tabindex="-1" aria-labelledby="syncModalLabel" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" id="syncModalLabel">
                    <span id="syncSpinner" class="spinner-border spinner-border-sm text-secondary me-2" role="status" style="display:none;"></span>
                    Sync log
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-0">
                <div id="syncLog"
                     class="font-monospace small p-3 overflow-auto"
                     style="min-height:200px; max-height:60vh; background:#1e1e1e; color:#d4d4d4;">
                </div>
            </div>

            <div class="modal-footer py-2">
                <span id="syncStatus" class="text-muted small me-auto"></span>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>

<script>
(function () {
    let syncRunning = false;
    let activeTriggerBtn = null;

    // ── Helpers ───────────────────────────────────────────────────────────────

    function appendLine(msg, type) {
        const log = document.getElementById('syncLog');
        const line = document.createElement('div');
        line.className = 'lh-base';
        line.style.color = type === 'error'   ? '#f48771'
                         : type === 'success' ? '#4ec9b0'
                         : '#d4d4d4';
        line.innerHTML = msg;
        log.appendChild(line);
        log.scrollTop = log.scrollHeight;

        if (type === 'success') {
            document.getElementById('syncStatus').textContent =
                line.textContent.replace(/[^\x20-\x7E]/g, '').trim();
        }
    }

    // Place names come from Wikidata — never inject them as raw HTML.
    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // One log line for one itinerary point, e.g.
    //   📍 Q3929 — Port Louis · via · 1832-05-01 🖼
    //   ⚠️ Q123 — Somewhere · no coordinates (not saved)
    function placeLine(p) {
        const name = escapeHtml(p.name ?? '(no label)');
        const meta = [p.type, p.date].filter(Boolean).map(escapeHtml).join(' · ');
        const base = `${escapeHtml(p.qid)} — ${name}${meta ? ' · ' + meta : ''}`;
        if (p.saved) return `📍 ${base}${p.has_image ? ' 🖼' : ''}`;
        return `<span style="color:#dcdcaa">⚠️ ${base} · ${escapeHtml(p.skipped ?? 'not saved')} (not saved)</span>`;
    }

    // Extra context to tack onto "Sync complete" when the terminating call
    // itself did real work (e.g. georeference.it corrected some specimens
    // right on the last page) — otherwise that final activity is invisible.
    function doneNote(step, data) {
        if (step === 'gbif_enrich')  return ` — GBIF enrich: ${data.updated ?? 0} specimen(s) updated`;
        if (step === 'georeference') return data.georeference_down
            ? ' — ⚠️ georeference.it was unreachable and was skipped'
            : ` — georeference.it: ${data.updated ?? 0} specimen(s) corrected`;
        return '';
    }

    function stepToMessage(data, redirectUrl) {
        const step  = data.step ?? data.status ?? '';
        const label = data.label ? ` — <em>${data.label}</em>` : '';

        if (data.status === 'done')  return { msg: `✅ Sync complete${doneNote(step, data)}`, type: 'success' };
        if (data.status === 'error') return { msg: '❌ ' + (data.message ?? 'Unknown error'), type: 'error' };

        switch (step) {
            case 'meta':
                return { msg: `📋 Metadata saved${label} &mdash; ${data.points ?? 0} itinerary point(s), participants saved` };
            case 'point': {
                const img     = data.has_image ? ' 🖼' : '';
                const deleted = data.deleted_points != null ? ` <span style="color:#888">(deleted ${data.deleted_points} old points)</span>` : '';
                const name    = data.place_name ? ` — ${data.place_name}` : '';
                return { msg: `📍 ${data.processed ?? ''}${name}${img}${deleted} (${data.remaining_points ?? 0} remaining)` };
            }
            case 'points_done':
                return { msg: `📍 Itinerary complete — ${data.points ?? 0}${data.total != null ? ' of ' + data.total : ''} point(s) saved` };
            case 'gbif':
                return { msg: `🔬 GBIF: ${data.gbif_count ?? 0} specimen(s) imported` };
            case 'gbif_enrich':
                return { msg: `🖼️ GBIF enrich: ${data.updated ?? 0} specimen(s) updated` };
            case 'georeference':
                return { msg: `🌍 georeference.it: ${data.updated ?? 0} specimen(s) corrected` };
            case 'bionomia':
                return { msg: `🧬 Bionomia: <em>${data.person ?? ''}</em> processed` };
            default:
                if (data.synced) {
                    const link = redirectUrl ? ` <a href="${redirectUrl}" class="text-success">View expedition →</a>` : '';
                    return { msg: `✅ ${data.map_items ?? 0} items${link}`, type: 'success' };
                }
                if (data.person)  return { msg: `✅ Person synced — ${data.specimens ?? 0} specimen(s), ${data.map_items ?? 0} map item(s)`, type: 'success' };
                if (data.next)   return { msg: `⏳ Step: ${step || 'processing'}...` };
                return { msg: JSON.stringify(data) };
        }
    }

    function setButtonRunning(btn) {
        if (!btn) return;
        btn.disabled = false; // keep clickable
        btn._originalHtml = btn._originalHtml ?? btn.innerHTML;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status"></span><span class="sync-btn-hint">Syncing… click to view</span>`;
        btn.onclick = () => reopenModal();
    }

    function setButtonIdle(btn, success, redirectUrl) {
        if (!btn) {
            // No trigger button — update the modal Close button instead
            if (success && redirectUrl) {
                const closeBtn = document.querySelector('#syncModal .modal-footer .btn-secondary');
                if (closeBtn) {
                    closeBtn.textContent = 'View expedition →';
                    closeBtn.classList.replace('btn-secondary', 'btn-primary');
                    closeBtn.removeAttribute('data-bs-dismiss');
                    closeBtn.onclick = () => window.location.href = redirectUrl;
                }
            }
            return;
        }
        btn.disabled = false;
        if (success && redirectUrl) {
            btn.innerHTML = 'View expedition →';
            btn.onclick = () => window.location.href = redirectUrl;
        } else if (success) {
            btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Reload';
            btn.onclick = () => window.location.reload();
        } else {
            btn.innerHTML = btn._originalHtml;
            btn.onclick = btn._originalOnclick;
        }
    }

    function reopenModal() {
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('syncModal'));
        modal.show();
        // Scroll log to bottom in case new lines arrived while closed
        const log = document.getElementById('syncLog');
        log.scrollTop = log.scrollHeight;
    }

    // ── Navigation warning ────────────────────────────────────────────────────

    window.addEventListener('beforeunload', function (e) {
        if (syncRunning) {
            e.preventDefault();
            e.returnValue = 'Sync is still running. If you leave, it will be interrupted.';
        }
    });

    // ── Chain runner ──────────────────────────────────────────────────────────

    async function runChain(startUrl, redirectUrl) {
            console.log('stepToMessage redirectUrl:', redirectUrl);
                console.log('runChain redirectUrl:', redirectUrl);
        let next = startUrl;

        while (next && syncRunning) {
            let data;
            try {
                const res = await fetch(next);
                if (!res.ok) throw new Error('HTTP ' + res.status);
                data = await res.json();
            } catch (err) {
                appendLine('❌ Request failed: ' + err.message, 'error');
                break;
            }

            // One line per itinerary point (what was updated from Wikidata)
            if (Array.isArray(data.places)) {
                data.places.forEach(p => appendLine(placeLine(p)));
            }

            const { msg, type } = stepToMessage(data, redirectUrl);
            appendLine(msg, type);

            // Stop after one expedition completes (don't cascade into the next unsynced one)
            next = (data.next && !data.synced) ? data.next : null;

            if (next) await new Promise(r => setTimeout(r, 80));
        }

        // Finished
        syncRunning = false;
        document.getElementById('syncSpinner').style.display = 'none';
        setButtonIdle(activeTriggerBtn, true, redirectUrl);
        activeTriggerBtn = null;
    }

    // ── Public entry point ────────────────────────────────────────────────────

    // Reset modal close button to default state
    document.getElementById('syncModal').addEventListener('show.bs.modal', function () {
        const closeBtn = document.querySelector('#syncModal .modal-footer .btn-secondary, #syncModal .modal-footer .btn-primary');
        if (closeBtn && closeBtn.textContent !== 'Close') {
            closeBtn.textContent = 'Close';
            closeBtn.classList.replace('btn-primary', 'btn-secondary');
            closeBtn.setAttribute('data-bs-dismiss', 'modal');
            closeBtn.onclick = null;
        }
    });

    window.startSync = function (startUrl, triggerBtn, redirectUrl) {
        // If already running, just reopen the modal
        if (syncRunning) {
            reopenModal();
            return;
        }

        const log     = document.getElementById('syncLog');
        const spinner = document.getElementById('syncSpinner');
        const modal   = bootstrap.Modal.getOrCreateInstance(document.getElementById('syncModal'));

        // Reset log
        log.innerHTML = '';
        document.getElementById('syncStatus').textContent = '';
        spinner.style.display = 'inline-block';

        // Store original onclick so we can restore it later
        if (triggerBtn) triggerBtn._originalOnclick = triggerBtn.getAttribute('onclick');

        activeTriggerBtn = triggerBtn;
        syncRunning = true;

        setButtonRunning(triggerBtn);
        modal.show();
        appendLine('🔄 Starting sync…');

        runChain(startUrl, redirectUrl);
    };
})();
</script>
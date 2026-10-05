<?php
require __DIR__ . '/layout.php';
$game = in_array($_GET['game'] ?? 'all', ['all', 'pokemon', 'mtg'], true) ? $_GET['game'] : 'all';
$q = trim($_GET['q'] ?? '');
render_head('Inventory', 'inventory', ['inventory.css']);
?>
    <main id="content" class="inventory-page">
        <div class="toolbar">
            <fieldset class="game-picker">
                <legend class="visually-hidden">Game</legend>
                <?php foreach (['all' => 'All games', 'pokemon' => 'Pokémon', 'mtg' => 'Magic'] as $v => $label): ?>
                    <label><input type="radio" name="game" value="<?= $v ?>" <?= $v === $game ? 'checked' : '' ?>><span><?= $label ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <div class="filter">
                <label for="filter" class="visually-hidden">Filter by name or set</label>
                <input type="search" id="filter" placeholder="Filter by name or set" value="<?= h($q) ?>" autocomplete="off">
            </div>
            <p id="summary" class="summary" aria-live="polite">Loading</p>
        </div>

        <div id="grid" class="grid"></div>
        <p id="message" class="message" aria-live="polite"></p>
        <div id="sentinel"></div>
    </main>

<script>
const LABELS = { pokemon: 'Pokémon', mtg: 'Magic' };
const LIMIT = 60;
const grid = document.getElementById('grid');
const msg = document.getElementById('message');
const summary = document.getElementById('summary');
const filter = document.getElementById('filter');
const state = { game: document.querySelector('input[name=game]:checked').value, q: filter.value.trim(), offset: 0, loading: false, done: false, token: 0 };

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* ---- Image lookups: two at a time, so the card APIs aren't flooded ---- */
let queue = [], active = 0, apiError = false;
function enqueue(job) { queue.push(job); pump(); }
function pump() {
    while (active < 2 && queue.length) {
        const job = queue.shift();
        active++;
        lookup(job).finally(() => { active--; pump(); });
    }
}
async function lookup({ game, id, art }) {
    try {
        const d = await (await fetch(`card_image.php?game=${game}&id=${id}`)).json();
        if (d.url) setImage(art, d.url);
        else art.classList.add('no-image');
        if (d.error && !apiError) { apiError = true; msg.textContent = 'Some images could not be loaded: ' + d.error; }
    } catch { art.classList.add('no-image'); }
}
function setImage(art, url) {
    const img = art.querySelector('img');
    img.onload = () => art.classList.add('loaded');
    img.onerror = () => art.classList.add('no-image');
    img.src = url;
}

function cardEl(c) {
    const el = document.createElement('article');
    el.className = 'card';
    const meta = [state.game === 'all' ? LABELS[c.game] : null, c.number ? 'No. ' + c.number : null].filter(Boolean);
    el.innerHTML = `
        <div class="art"><span class="ph">${esc(c.name)}</span><img alt="${esc(c.name)}"></div>
        <span class="qty" title="Copies owned">x${c.quantity}</span>
        <h3>${esc(c.name)}</h3>
        <p class="set">${esc(c.set_name || '')}</p>
        <p class="meta">${meta.map(esc).join('<span class="gap"></span>')}</p>`;
    const art = el.querySelector('.art');
    if (c.image_url) setImage(art, c.image_url);
    else if (c.image_url === '') art.classList.add('no-image');
    else enqueue({ game: c.game, id: c.id, art });
    return el;
}

/* ---- Paging ---- */
const io = new IntersectionObserver(e => { if (e[0].isIntersecting) loadMore(); }, { rootMargin: '800px' });

async function loadMore() {
    if (state.loading || state.done) return;
    state.loading = true;
    const token = state.token;
    try {
        const p = new URLSearchParams({ game: state.game, q: state.q, offset: state.offset, limit: LIMIT });
        const d = await (await fetch('inventory_api.php?' + p)).json();
        if (token !== state.token) return;
        if (d.error) throw new Error(d.error);
        d.cards.forEach(c => grid.appendChild(cardEl(c)));
        state.offset += d.cards.length;
        if (d.cards.length < LIMIT) state.done = true;
        if (d.total_rows !== undefined) {
            summary.textContent = d.total_rows
                ? `${d.total_rows} unique cards, ${d.total_qty} total copies`
                : '';
            msg.textContent = d.total_rows ? '' : 'No cards found. Try a different filter, or add cards from Import.';
        }
    } catch (e) {
        if (token === state.token) { msg.textContent = 'Could not load cards: ' + e.message; state.done = true; }
    } finally {
        if (token === state.token) { state.loading = false; io.unobserve(sentinel); io.observe(sentinel); }
    }
}

function reset() {
    state.token++; state.loading = false; state.done = false; state.offset = 0;
    queue = []; apiError = false;
    grid.innerHTML = ''; msg.textContent = '';
    loadMore();
}

document.querySelectorAll('input[name=game]').forEach(r => r.addEventListener('change', () => { state.game = r.value; reset(); }));
let timer;
filter.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { state.q = filter.value.trim(); reset(); }, 300); });
const sentinel = document.getElementById('sentinel');
reset();
</script>
<?php render_foot();

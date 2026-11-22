<?php
/**
 * Customer Lookup: customers check which cards the store has in stock. No sign-in, nothing saved,
 * no way to change the inventory. The list clears itself after a few minutes of no use.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/partials.php';
app_db();
header('Cache-Control: no-store');
page_head('Card Lookup', '', 'lists-page kiosk-page', ['pages.css'], false);
?>
        <div class="page-top">
            <div>
                <h1>Card lookup</h1>
                <p class="note kiosk-intro">See which cards we have in stock. Add cards one at a time, or paste a deck list. When we have more than one printing of a card, you can choose the one you want.</p>
            </div>
            <button type="button" id="reset">Start over</button>
        </div>

        <section class="list-detail">
            <fieldset class="game-picker" id="games">
                <legend class="visually-hidden">Game</legend>
                <label><input type="radio" name="game" value="pokemon" checked><span>Pokémon</span></label>
                <label><input type="radio" name="game" value="mtg"><span>Magic</span></label>
            </fieldset>

            <form id="add" class="add-form" autocomplete="off">
                <label for="card" class="visually-hidden">Card name</label>
                <input type="text" id="card" list="names" placeholder="Card name" required>
                <datalist id="names"></datalist>
                <label for="qty" class="visually-hidden">How many you need</label>
                <input type="number" id="qty" value="1" min="1" max="99">
                <button type="submit" class="primary">Add card</button>
            </form>

            <details id="paste-box" class="paste">
                <summary>Paste a deck list instead</summary>
                <label for="paste" class="visually-hidden">Deck list</label>
                <textarea id="paste" rows="7" placeholder="One card per line, starting with how many you need:&#10;4 Lightning Bolt&#10;2 Counterspell"></textarea>
                <button type="button" id="check-list" class="primary">Check list</button>
            </details>

            <div id="notice" class="notice warn" role="status" hidden></div>
            <p id="summary" class="summary-line" aria-live="polite"></p>

            <div class="table-wrap" id="table-wrap" hidden>
                <table class="list-table kiosk-table">
                    <thead><tr><th>Card</th><th class="num">You need</th><th class="num">In stock</th><th>Status</th><th></th></tr></thead>
                    <tbody id="rows"></tbody>
                </table>
            </div>
            <p id="empty" class="note">Your cards will show up here.</p>

            <section id="sendBox" class="send-box" hidden>
                <h2>Want these cards? Send your list to the counter</h2>
                <p class="note">Staff get your list on their screen, so nobody has to type it in again.</p>
                <form id="sendForm" class="send-form" autocomplete="off">
                    <label for="customer" class="visually-hidden">Your name</label>
                    <input type="text" id="customer" placeholder="Your name" maxlength="40" required>
                    <button type="submit" class="primary" id="sendBtn">Send to the counter</button>
                </form>
            </section>
            <div id="sentBox" class="notice success sent-box" role="status" hidden></div>
        </section>

<script>
const $ = id => document.getElementById(id);
const rows = $('rows'), notice = $('notice'), summary = $('summary'), empty = $('empty'), wrap = $('table-wrap');
let game = 'pokemon';
let items = [];            // [{name, wanted, in_stock, copies:[...], pick}] kept only in this page, never saved
                           // copies = one per printing in stock; pick = the key of the printing chosen ('' = any)
const open = new Set();    // cards whose copies are showing
const IDLE_MS = 4 * 60 * 1000;

function el(tag, cls, text) { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
function say(msg) { notice.textContent = msg; notice.hidden = !msg; }

async function api(body) {
  const r = await fetch('kiosk_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ game, ...body }) });
  const d = await r.json();
  if (d.error) throw new Error(d.error);
  return d;
}

// New cards go to the end of the list; asking for a card again adds to how many you need
function merge(found) {
  for (const f of found) {
    const old = items.find(x => x.name.toLowerCase() === f.name.toLowerCase());
    if (old) {
      old.wanted += f.wanted; old.in_stock = f.in_stock; old.copies = f.copies;
      if (old.pick && !f.copies.some(c => c.key === old.pick)) old.pick = '';   // that printing sold out meanwhile
    } else items.push({ ...f, pick: '' });
  }
}

async function add(list) {
  say('');
  try { merge((await api({ items: list })).items); render(); }
  catch (e) { say(e.message); }
}

async function refreshAll() {          // after switching games
  if (!items.length) return render();
  const list = items.map(x => [x.name, x.wanted]);
  items = [];
  await add(list);
}

function render() {
  rows.replaceChildren();
  wrap.hidden = !items.length;
  empty.hidden = items.length > 0;
  let full = 0;
  // Cards we have first, then partly in stock, then not in stock at the bottom (same order as the staff List Builder)
  const rank = it => it.in_stock >= it.wanted ? 0 : it.in_stock > 0 ? 1 : 2;
  const shown = [...items].sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name));
  for (const it of shown) {
    const have = it.in_stock, want = it.wanted;
    if (have >= want) full++;
    const status = have === 0 ? ['bad', 'Not in stock'] : have >= want ? ['ok', 'In stock'] : ['warn', `Short by ${want - have}`];
    const tr = el('tr');
    const nameTd = el('td', 'card-name');
    nameTd.appendChild(el('span', 'card-title', it.name));
    if (it.copies.length === 1) nameTd.appendChild(el('span', 'printing-one', it.copies[0].label));   // only one printing: just say which
    tr.appendChild(nameTd);
    tr.appendChild(el('td', 'num', String(want)));
    tr.appendChild(el('td', 'num', String(have)));
    const st = el('td'); st.appendChild(el('span', 'status ' + status[0], status[1])); tr.appendChild(st);
    const act = el('td', 'actions');
    const key = it.name.toLowerCase();
    if (it.copies.length) {
      const t = el('button', 'link', open.has(key) ? 'Hide copies' : 'See copies');
      t.type = 'button';
      t.setAttribute('aria-expanded', String(open.has(key)));
      t.onclick = () => { open.has(key) ? open.delete(key) : open.add(key); render(); };
      act.appendChild(t);
    }
    const rm = el('button', 'link', 'Remove');
    rm.type = 'button';
    rm.setAttribute('aria-label', 'Remove ' + it.name);
    rm.onclick = () => { items = items.filter(x => x !== it); open.delete(key); render(); };
    act.appendChild(rm);
    tr.appendChild(act);
    rows.appendChild(tr);
    if (it.copies.length > 1) {          // more than one printing: a chooser on its own line, full width
      tr.classList.add('has-printing');
      const pr = el('tr', 'printing-row');
      const ptd = el('td'); ptd.colSpan = 5;
      ptd.appendChild(printingPicker(it));
      pr.appendChild(ptd);
      rows.appendChild(pr);
    }

    if (it.copies.length && open.has(key)) {
      const sub = el('tr', 'copies-row');
      const td = el('td'); td.colSpan = 5;
      const tbl = el('table', 'copies-table');
      const head = el('tr');
      const choose = it.copies.length > 1;
      ['Set', 'Number', 'Condition', 'Finish', 'In stock'].concat(choose ? [''] : []).forEach((h, i) => head.appendChild(el('th', i === 4 ? 'num' : '', h)));
      const thead = el('thead'); thead.appendChild(head);
      const tbody = el('tbody');
      for (const c of it.copies) {
        const r = el('tr', it.pick === c.key ? 'picked' : '');
        r.appendChild(el('td', '', c.set || '–'));
        r.appendChild(el('td', '', c.number || '–'));
        const cond = el('td'); cond.appendChild(el('span', 'tag cond cond-' + c.condition.toLowerCase(), c.condition_label)); r.appendChild(cond);
        r.appendChild(el('td', '', c.finish_label));
        r.appendChild(el('td', 'num', String(c.quantity)));
        if (choose) {
          const cell = el('td', 'pick-cell');
          if (it.pick === c.key) cell.appendChild(el('span', 'tag your-pick', 'Your pick'));
          else {
            const b = el('button', 'link', 'Choose');
            b.type = 'button';
            b.setAttribute('aria-label', `Choose ${it.name}, ${c.label}`);
            b.onclick = () => { it.pick = c.key; render(); };
            cell.appendChild(b);
          }
          r.appendChild(cell);
        }
        tbody.appendChild(r);
      }
      tbl.append(thead, tbody);
      td.appendChild(tbl);
      sub.appendChild(td);
      rows.appendChild(sub);
    }
  }
  summary.textContent = items.length ? `${full} of ${items.length} ${items.length === 1 ? 'card is' : 'cards are'} fully in stock.` : '';
  // The send box shows once there's a list; after sending, it comes back only if the list changes
  const sentNow = sent && sent.key === listKey();
  $('sendBox').hidden = !items.length || sentNow;
  $('sentBox').hidden = !sentNow;
}
const listKey = () => game + '|' + items.map(x => x.name.toLowerCase() + '×' + x.wanted + '@' + (x.pick || '')).sort().join('|');

/* Choosing a printing: a drop-down under the card name when we have more than one printing in stock */
function printingPicker(it) {
  const box = el('div', 'printing');
  const id = 'pick-' + it.name.toLowerCase().replace(/[^a-z0-9]+/g, '-');
  const lab = el('label', 'printing-label', 'Printing');
  lab.htmlFor = id;
  const sel = el('select', 'printing-pick');
  sel.id = id;
  sel.appendChild(new Option('Any printing (best condition first)', ''));
  for (const c of it.copies) sel.appendChild(new Option(`${c.label} (${c.quantity} left)`, c.key));
  sel.value = it.pick || '';
  sel.onchange = () => { it.pick = sel.value; render(); };
  box.append(lab, sel);
  const chosen = it.copies.find(c => c.key === it.pick);
  if (chosen && chosen.quantity < it.wanted) {
    const rest = Math.min(it.wanted, it.in_stock) - chosen.quantity;
    box.appendChild(el('p', 'printing-note', rest > 0
      ? `We have ${chosen.quantity} of this printing. The other ${rest} will be another printing.`
      : `We have ${chosen.quantity} of this printing.`));
  }
  return box;
}
const pickOf = it => {
  const c = it.copies.find(x => x.key === it.pick);
  return c ? { set: c.set, number: c.number, condition: c.condition, finish: c.finish } : null;
};
let sent = null;   // {key, list, customer} after a successful send

function reset(msg) {
  items = []; open.clear(); sent = null; $('customer').value = '';
  $('paste').value = ''; $('card').value = ''; $('qty').value = 1; $('paste-box').open = false;
  render(); say(msg || '');
  window.scrollTo(0, 0);
}

/* Adding cards */
$('add').addEventListener('submit', e => {
  e.preventDefault();
  const name = $('card').value.trim();
  if (!name) return;
  add([[name, Math.max(1, parseInt($('qty').value, 10) || 1)]]);
  $('card').value = ''; $('qty').value = 1; $('card').focus();
});
$('check-list').addEventListener('click', async () => {
  const text = $('paste').value.trim();
  if (!text) return;
  say('');
  try {
    const d = await api({ text });
    merge(d.items);
    render();
    if (d.skipped.length) say(`Skipped ${d.skipped.length} line(s) that didn't start with a number, like "${d.skipped[0]}".`);
    $('paste').value = ''; $('paste-box').open = false;
  } catch (e) { say(e.message); }
});
/* Sending the list to the staff List Builder */
$('sendForm').addEventListener('submit', async e => {
  e.preventDefault();
  const customer = $('customer').value.trim();
  if (!customer || !items.length) return;
  $('sendBtn').disabled = true; say('');
  try {
    const d = await api({ action: 'send', customer, items: items.map(x => [x.name, x.wanted, pickOf(x)]) });
    sent = { key: listKey(), list: d.list, customer: d.customer };
    $('sentBox').replaceChildren(
      el('strong', '', 'Sent! '),
      document.createTextNode(`Your list (${d.cards} ${d.cards === 1 ? 'card' : 'cards'}) is at the counter as list #${d.list} under "${d.customer}".` +
        (d.chosen ? ` The printings you chose are on it too.` : '') + ` Let a staff member know you're ready.`));
    render();
  } catch (err) { say(err.message); }
  finally { $('sendBtn').disabled = false; }
});
$('games').addEventListener('change', e => { game = e.target.value; $('names').replaceChildren(); refreshAll(); });
$('reset').addEventListener('click', () => reset());

/* Name suggestions from what's in stock */
let sugTimer, lastSug = '';
$('card').addEventListener('input', () => {
  clearTimeout(sugTimer);
  sugTimer = setTimeout(async () => {
    const q = $('card').value.trim();
    if (q.length < 2 || q === lastSug) return;
    lastSug = q;
    try {
      const list = await fetch('kiosk_api.php?' + new URLSearchParams({ game, suggest: q })).then(r => r.json());
      if (Array.isArray(list)) $('names').replaceChildren(...list.map(n => Object.assign(document.createElement('option'), { value: n })));
    } catch (e) { /* optional */ }
  }, 200);
});

/* Clear the list when nobody has used the screen for a while */
let idle;
function poke() { clearTimeout(idle); idle = setTimeout(() => { if (items.length || $('paste').value) reset('The list was cleared after a few minutes without use.'); }, IDLE_MS); }
['pointerdown', 'keydown', 'pointermove', 'wheel'].forEach(ev => document.addEventListener(ev, poke, { passive: true }));
poke();
render();
</script>
<?php page_foot(false); ?>

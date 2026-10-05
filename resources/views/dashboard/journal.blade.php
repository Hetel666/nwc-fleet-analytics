<!doctype html>
<html lang="az">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Statuslar jurnalı</title>
<style>
body { margin: 0; font: 13px Arial, sans-serif; color: #17212b; background: #fff; }
header { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 0; }
button, a { color: #1261d9; cursor: pointer; }
table { border-collapse: collapse; width: 100%; table-layout: fixed; }
th, td { border-bottom: 1px solid #dde3e9; padding: 7px 5px; text-align: left; overflow-wrap: anywhere; vertical-align: top; }
th { background: #f4f7fa; }
details { position: relative; }
.choices { position: absolute; z-index: 5; width: 220px; max-height: 240px; overflow: auto; background: white; border: 1px solid #ccd5df; box-shadow: 0 3px 10px #0002; padding: 8px; }
.choices label { display: block; padding: 4px 0; }
.choices input[type=search] { width: 95%; margin-bottom: 5px; }
.status { display: inline-block; padding: 3px 5px; border-radius: 4px; background: #eef1f4; }
.status[data-status="Əsaslandırıldı"] { background: #d9f4e4; color: #146234; }
.status[data-status="Təsdiqlənmiş pozuntu"] { background: #ffe0e0; color: #9e2020; }
.status[data-status="Araşdırılır"] { background: #ffe6cb; color: #804700; }
.status[data-status="Sistem xətası"] { background: #fff1af; color: #665100; }
#summary { display: flex; gap: 12px; flex-wrap: wrap; padding-bottom: 12px; }
@media(max-width: 700px) { table { min-width: 1000px; } .table-wrap { overflow-x: auto; } }
</style>
</head>
<body>
<header><strong>Statuslar jurnalı</strong><span id="count"></span><button type="button" id="clear">Filtrləri təmizlə</button><a id="export" href="#">Excel</a></header>
<div id="summary"></div>
<div class="table-wrap"><table><thead><tr>
@foreach ($columns as $key => $label)
<th><details data-column="{{ $key }}"><summary>{{ $label }}</summary><div class="choices"><input type="search" aria-label="{{ $label }}: axtar"><label><input type="checkbox" data-all checked> Hamısını seç</label><div class="values"></div></div></details></th>
@endforeach
</tr></thead><tbody id="rows"></tbody></table></div>
<header><button id="prev" type="button">Əvvəlki</button><span id="page"></span><button id="next" type="button">Növbəti</button><select id="size" aria-label="Səhifələmə"><option>50</option><option>100</option></select></header>
<script>
const rows = @json($rows);
const columns = @json(array_keys($columns));
const filters = new Map();
let page = 1;
const size = document.getElementById('size');
const filtered = (except = null) => rows.filter(row => [...filters].every(([key, values]) => key === except || values.has(String(row[key] ?? ''))));
function render() {
    const matching = filtered();
    const pages = Math.max(1, Math.ceil(matching.length / Number(size.value)));
    page = Math.min(page, pages);
    const body = document.getElementById('rows');
    body.replaceChildren();
    matching.slice((page - 1) * Number(size.value), page * Number(size.value)).forEach(row => {
        const tr = document.createElement('tr');
        columns.forEach(key => {
            const td = document.createElement('td');
            const value = String(row[key] ?? '');
            if (key === 'status') {
                const span = document.createElement('span'); span.className = 'status'; span.dataset.status = value; span.textContent = value; td.append(span);
            } else { td.textContent = value; }
            tr.append(td);
        });
        body.append(tr);
    });
    document.getElementById('count').textContent = `${matching.length} / ${rows.length}`;
    document.getElementById('page').textContent = `${page} / ${pages}`;
    document.getElementById('prev').disabled = page <= 1;
    document.getElementById('next').disabled = page >= pages;
    const summary = document.getElementById('summary'); summary.replaceChildren();
    const counts = new Map(); matching.forEach(row => counts.set(row.status, (counts.get(row.status) || 0) + 1));
    counts.forEach((count, status) => { const span = document.createElement('span'); span.className = 'status'; span.dataset.status = status; span.textContent = `${status}: ${count}`; summary.append(span); });
    const url = new URL(location.href); url.searchParams.set('export', '1');
    [...url.searchParams.keys()].filter(key => key.startsWith('columns[')).forEach(key => url.searchParams.delete(key));
    filters.forEach((values, key) => {
        if (!values.size) { url.searchParams.append(`columns[${key}][]`, '__journal_no_values__'); }
        else { values.forEach(value => url.searchParams.append(`columns[${key}][]`, value)); }
    });
    document.getElementById('export').href = url.toString();
}
document.querySelectorAll('details[data-column]').forEach(details => {
    const key = details.dataset.column;
    const list = details.querySelector('.values');
    const search = details.querySelector('input[type=search]');
    function choices() {
        const values = [...new Set(filtered(key).map(row => String(row[key] ?? '')))].sort((a, b) => a.localeCompare(b));
        list.replaceChildren();
        values.filter(value => value.toLocaleLowerCase().includes(search.value.toLocaleLowerCase())).forEach(value => {
            const label = document.createElement('label'); const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = !filters.has(key) || filters.get(key).has(value);
            checkbox.addEventListener('change', () => {
                const selection = filters.get(key) || new Set(rows.map(row => String(row[key] ?? '')));
                checkbox.checked ? selection.add(value) : selection.delete(value); filters.set(key, selection); page = 1; render();
            });
            label.append(checkbox, document.createTextNode(` ${value || '—'}`)); list.append(label);
        });
        details.querySelector('[data-all]').checked = !filters.has(key);
    }
    details.addEventListener('toggle', () => { if(details.open) choices(); });
    search.addEventListener('input', choices);
    details.querySelector('[data-all]').addEventListener('change', event => { event.target.checked ? filters.delete(key) : filters.set(key, new Set()); page = 1; choices(); render(); });
});
document.getElementById('clear').onclick = () => { filters.clear(); page = 1; document.querySelectorAll('details').forEach(item => item.open = false); render(); };
document.getElementById('prev').onclick = () => { page--; render(); };
document.getElementById('next').onclick = () => { page++; render(); };
size.onchange = () => { page = 1; render(); };
render();
</script>
</body></html>

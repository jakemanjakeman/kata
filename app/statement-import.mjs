import {statementLines, parseStatement} from './statement-parser.mjs';
import {parseStatementCsv} from './statement-csv.mjs';

const byId = id => document.getElementById(id);
const config = JSON.parse(byId('statement-config').textContent);
const status = byId('statement-status'), review = byId('statement-review'), container = byId('statement-rows');
const parseButton = byId('statement-parse');
let loading = false, previewAccount = '', pendingReview = false;

function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text) node.textContent = text;
    if (className) node.className = className;
    return node;
}

function field(parent, title, name, value, options) {
    const label = element('label', title);
    const input = document.createElement(options ? 'select' : 'input');
    input.dataset.field = name;
    if (options) Object.entries(options).forEach(([value, text]) => { const opt = element('option', text); opt.value = value; input.append(opt); });
    else {
        input.type = name === 'date' ? 'date' : name === 'amount' ? 'number' : 'text';
        if (name === 'amount') { input.min = '0.01'; input.max = '999999999.99'; input.step = '0.01'; }
        if (name === 'date') {
            input.min = config.month + '-01';
            const [year, month] = config.month.split('-').map(Number);
            input.max = config.month + '-' + new Date(Date.UTC(year, month, 0)).getUTCDate();
        }
        if (name === 'name') input.maxLength = 240;
    }
    input.value = value;
    input.required = true;
    label.append(input); parent.append(label);
}

function rowKey(row) { return [row.date || '', row.name.trim().toLowerCase(), Number(row.amount).toFixed(2), row.type, row.account_id].join('|'); }

function editorRows() {
    return Array.from(byId('audit-items').children).map(item => {
        const row = {};
        item.querySelectorAll('[data-field]').forEach(input => { row[input.dataset.field] = input.value; });
        return row;
    });
}

function renderRows(rows) {
    container.replaceChildren();
    const existing = new Set(editorRows().map(rowKey));
    rows.forEach((row, index) => {
        const card = element('div', '', 'statement-row');
        const label = element('label', '', 'statement-select');
        const check = document.createElement('input'); check.type = 'checkbox'; check.dataset.include = '1';
        label.append(check, document.createTextNode(`Include item ${index + 1} (${row.sourceLabel || 'page ' + row.page})`));
        card.append(label);
        card.append(element('p', row.source, 'statement-source-line'));
        const notes = row.notes.slice();
        const key = rowKey({...row, account_id:previewAccount});
        if (existing.has(key)) notes.push('Possible duplicate of an audit item or another statement row. Only include if this is a separate transaction.');
        existing.add(key);
        if (notes.length) card.append(element('p', notes.join(' '), 'statement-notes'));
        const grid = element('div', '', 'audit-item');
        field(grid, 'Date', 'date', row.date);
        field(grid, 'Description', 'name', row.name);
        field(grid, 'Amount ($)', 'amount', row.amount);
        field(grid, 'Type', 'type', row.type, {expense:'Expense', income:'Income'});
        field(grid, 'Category', 'category', 'uncategorized', config.categories);
        card.append(grid); container.append(card);
    });
    review.hidden = rows.length === 0;
    pendingReview = rows.length > 0;
}

parseButton.addEventListener('click', async () => {
    if (loading) return;
    const file = byId('statement-file').files[0];
    const account = byId('statement-account').value;
    if (!file || !account) { status.textContent = 'Choose a PDF or CSV and an account first.'; return; }
    if (file.size > 15 * 1024 * 1024) { status.textContent = 'Choose a file smaller than 15 MB.'; return; }
    loading = true; parseButton.disabled = true;
    review.hidden = true; byId('statement-source').hidden = true;
    container.replaceChildren(); pendingReview = false;
    byId('statement-text').textContent = '';
    status.textContent = 'Reading statement in your browser…';
    let task;
    try {
        if (/\.csv$/i.test(file.name) || /(?:text\/csv|application\/csv)/i.test(file.type)) {
            const csv = await file.text();
            const parsed = parseStatementCsv(csv, config.month, byId('statement-mode').value);
            if (parsed.rows.length > 1000) throw new Error('More than 1,000 candidate items were found. Split the CSV into smaller files.');
            previewAccount = account;
            renderRows(parsed.rows);
            byId('statement-text').textContent = parsed.skipped.map(row => `Skipped CSV line ${row.line}: ${row.reason}\n${row.text}`).join('\n\n') + '\n\nOriginal CSV:\n' + csv;
            byId('statement-source').hidden = false;
            status.textContent = `${parsed.format}: ${parsed.rows.length} candidate items found for ${byId('statement-account').selectedOptions[0].textContent}; ${parsed.skipped.length} rows skipped. Review skipped rows below and check the selected items before adding. Nothing has been saved.`;
            return;
        }
        const data = new Uint8Array(await file.arrayBuffer());
        if (String.fromCharCode(...data.slice(0,5)) !== '%PDF-') throw new Error('Choose a PDF or a file ending in .csv.');
        const pdfjs = await import('./vendor/pdfjs/pdf.min.mjs');
        pdfjs.GlobalWorkerOptions.workerSrc = new URL('./vendor/pdfjs/pdf.worker.min.mjs', import.meta.url).href;
        task = pdfjs.getDocument({data, isEvalSupported:false, cMapUrl:new URL('./vendor/pdfjs/cmaps/', import.meta.url).href, cMapPacked:true, standardFontDataUrl:new URL('./vendor/pdfjs/standard_fonts/', import.meta.url).href});
        const timeout = setTimeout(() => { task.destroy(); }, 60000);
        try {
            const pdf = await task.promise;
            if (pdf.numPages > 60) throw new Error('Choose a statement with 60 pages or fewer.');
            const lines = [];
            for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
                status.textContent = `Reading page ${pageNumber} of ${pdf.numPages}…`;
                const page = await pdf.getPage(pageNumber);
                const text = await page.getTextContent();
                lines.push(...statementLines(text.items, pageNumber));
                page.cleanup();
                if (lines.length > 30000) throw new Error('This statement is too large to preview. Split it into smaller PDFs.');
            }
            const parsed = parseStatement(lines, config.month, byId('statement-mode').value);
            if (parsed.rows.length > 1000) throw new Error('More than 1,000 candidate items were found. Split the statement into smaller PDFs.');
            previewAccount = account;
            renderRows(parsed.rows);
            byId('statement-text').textContent = parsed.skipped.map(line => `Needs review, page ${line.page}: ${line.text} (${line.reason})`).join('\n') + '\n\n' + lines.map(line => `Page ${line.page}: ${line.text}`).join('\n');
            byId('statement-source').hidden = lines.length === 0;
            status.textContent = parsed.rows.length
                ? `${parsed.rows.length} candidate items found for ${byId('statement-account').selectedOptions[0].textContent}. ${parsed.skipped.length} dated lines could not be parsed. Review against your PDF; this is not a complete or reconciled statement. Nothing has been saved.`
                : 'No transaction rows could be read. Scanned PDFs need OCR first; other layouts may need manual entry. Check extracted text below if available.';
        } finally { clearTimeout(timeout); }
    } catch (error) {
        status.textContent = error.name === 'PasswordException' ? 'This PDF is password-protected. Choose an unlocked copy.' : `Could not preview the statement: ${error.message || 'Please try a smaller PDF or CSV.'}`;
    } finally {
        if (task) { try { await task.destroy(); } catch (_) {} }
        loading = false; parseButton.disabled = false;
    }
});

byId('statement-add').addEventListener('click', () => {
    const selected = [];
    for (const card of container.children) {
        if (!card.querySelector('[data-include]').checked) continue;
        const row = {account_id:previewAccount};
        for (const input of card.querySelectorAll('[data-field]')) {
            if (!input.reportValidity()) return;
            row[input.dataset.field] = input.value;
        }
        if (!row.name.trim()) { status.textContent = 'Enter a description for each selected item.'; return; }
        selected.push({card, row});
    }
    if (!selected.length) { status.textContent = 'Tick the items you have reviewed before adding them.'; return; }
    const currentCount = editorRows().filter(row => row.name || row.amount || row.account_id || row.date).length;
    if (currentCount + selected.length > config.maxRows) { status.textContent = `This server supports ${config.maxRows} items per audit. Select fewer items or increase the server input limit.`; return; }
    byId('audit-form').dispatchEvent(new CustomEvent('audit-import', {detail:selected.map(item => item.row)}));
    selected.forEach(item => item.card.remove());
    pendingReview = container.children.length > 0;
    review.hidden = !pendingReview;
    status.textContent = `Added ${selected.length} reviewed items to the editor. Save the audit when you are ready. Nothing has been saved yet.`;
});

byId('statement-discard').addEventListener('click', () => {
    container.replaceChildren(); byId('statement-text').textContent = '';
    review.hidden = true; byId('statement-source').hidden = true;
    byId('statement-file').value = ''; pendingReview = false;
    status.textContent = 'Preview discarded. Audit items are unchanged.';
});
window.addEventListener('beforeunload', event => { if (pendingReview) { event.preventDefault(); event.returnValue = ''; } });
byId('audit-form').addEventListener('submit', () => { pendingReview = false; });

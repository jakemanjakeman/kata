// Deliberately conservative: only dated rows become transaction candidates.
// Positions from PDF.js keep debit/credit/balance columns distinguishable.
export function statementLines(items, page) {
    const lines = [];
    for (const item of items) {
        if (!item.str || !item.str.trim()) continue;
        const x = item.transform[4], y = item.transform[5];
        let line = lines.find(line => Math.abs(line.y - y) < 2.5);
        if (!line) { line = {page, y, cells: []}; lines.push(line); }
        line.cells.push({text: item.str.trim(), x, width: item.width || 0});
    }
    return lines.sort((a, b) => b.y - a.y).map(line => {
        line.cells.sort((a, b) => a.x - b.x);
        line.text = line.cells.map(cell => cell.text).join(' ');
        return line;
    });
}

const dateStart = /^(\d{4}-\d{2}-\d{2}|\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{4}|[\/-]\d{2})?|(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2}(?:,?\s+\d{4})?)(?=\s|$)/i;
const moneyPattern = /(?:[-+]?\$?\(?\d[\d,]*\.\d{2}\)?(?:\s*(?:CR|DR))?)/gi;
const months = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];

function parseDate(raw, month) {
    let year, m, day;
    if (/^\d{4}-/.test(raw)) [year, m, day] = raw.split('-').map(Number);
    else if (/^\d/.test(raw)) {
        const parts = raw.split(/[\/-]/).map(Number);
        [m, day] = parts; year = parts[2];
        if (year < 100) year += 2000;
    } else {
        const parts = raw.replace(/[,.]/g, '').split(/\s+/);
        m = months.indexOf(parts[0].slice(0,3).toLowerCase()) + 1;
        day = Number(parts[1]); year = Number(parts[2]) || undefined;
    }
    const inferred = !year;
    if (!year) {
        year = Number(month.slice(0,4));
        const auditM = Number(month.slice(5));
        if (auditM === 1 && m === 12) year--;
        if (auditM === 12 && m === 1) year++;
    }
    const date = `${year}-${String(m).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
    const parsed = new Date(date + 'T12:00:00Z');
    return {date: !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0,10) === date ? date : '', inferred};
}

function moneyTokens(text) {
    return Array.from(text.matchAll(moneyPattern)).filter(match => {
        const before = text[match.index - 1] || '';
        const after = text[match.index + match[0].length] || '';
        return !/[\d.]/.test(before) && !/[\d.%]/.test(after);
    });
}

export function parseStatement(lines, month, mode = 'card') {
    const rows = [], skipped = [];
    let columns = [];
    for (const line of lines) {
        const first = line.text.match(dateStart);
        if (!first) {
            // Column positions are useful only when distinct headings exist.
            const headings = line.cells.map(cell => {
                const text = cell.text.toLowerCase();
                const kind = /balance/.test(text) ? 'balance' : /withdrawal|debit|money out/.test(text) ? 'expense' : /deposit|credit|money in/.test(text) ? 'income' : /amount/.test(text) ? 'amount' : '';
                return {kind, x: cell.x + cell.width / 2};
            }).filter(cell => cell.kind);
            if (headings.length && /date|description|balance|debit|credit|withdrawal|deposit|amount/i.test(line.text)) columns = headings;
            continue;
        }
        const notes = [];
        const parsed = parseDate(first[0], month);
        if (parsed.date && !parsed.date.startsWith(month + '-')) {
            skipped.push({...line, reason: `Outside audit month ${month} (${parsed.date}).`});
            continue;
        }
        let rest = line.text.slice(first[0].length).trim();
        const posting = rest.match(dateStart);
        if (posting) { rest = rest.slice(posting[0].length).trim(); notes.push('Two dates found; using the first.'); }
        const amounts = moneyTokens(rest);
        if (!amounts.length) { skipped.push({...line, reason: 'Date found, but no amount detected.'}); continue; }
        let amountMatch = amounts[amounts.length - 1], type = '', resolved = false;
        // Prefer a transaction column over a running balance when positions are available.
        const positioned = [];
        for (const cell of line.cells) {
            for (const match of moneyTokens(cell.text)) {
                const center = cell.x + cell.width * (match.index + match[0].length / 2) / Math.max(cell.text.length, 1);
                const nearest = columns.slice().sort((a,b) => Math.abs(a.x-center)-Math.abs(b.x-center))[0];
                if (nearest && Math.abs(nearest.x-center) < 100) positioned.push({raw:match[0], kind:nearest.kind});
            }
        }
        const candidates = positioned.filter(item => item.kind !== 'balance' && Number(item.raw.replace(/[^\d.]/g,'')) > 0);
        if (candidates.length === 1) {
            const candidate = candidates[0];
            const found = amounts.find(match => match[0].trim() === candidate.raw.trim());
            if (found) { amountMatch = found; type = candidate.kind === 'amount' ? '' : candidate.kind; resolved = true; }
        }
        const rawAmount = amountMatch[0];
        const amount = rawAmount.replace(/[^\d.]/g, '');
        if (amounts.length > 1 && !resolved) notes.push('Multiple amounts found; check the amount against the statement.');
        const signedCredit = /CR\b/i.test(rawAmount) || /[-(]/.test(rawAmount);
        if (!type) {
            type = mode === 'bank' ? (/-|DR\b|\(/i.test(rawAmount) ? 'expense' : 'income') : (signedCredit ? 'income' : 'expense');
            notes.push('Income/expense inferred from the selected statement format; verify it.');
        }
        const description = rest.slice(0, amounts[0].index).trim().replace(/\s+/g, ' ');
        if (!description) notes.push('Description needs review.');
        if (!parsed.date) notes.push('Date could not be read; enter it manually.');
        if (parsed.inferred) notes.push('Year inferred from the audit month.');
        if (/payment|transfer|refund|reversal/i.test(description) || signedCredit) notes.push('Possible payment, transfer, or refund: check whether this belongs in income/expenses.');
        rows.push({date:parsed.date, name:description, amount, type, category:'uncategorized', source:line.text, page:line.page, notes});
    }
    return {rows, skipped};
}

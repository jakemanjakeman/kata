// CSV records can contain quoted commas, escaped quotes, and embedded newlines.
export function readCsv(text) {
    text = text.replace(/^\uFEFF/, '');
    const records = [];
    let values = [], value = '', quoted = false, closed = false, line = 1, startLine = 1;
    function finish() {
        values.push(value); value = ''; closed = false;
        if (values.some(cell => cell.trim())) records.push({values, line:startLine});
        values = [];
        if (records.length > 30000) throw new Error('CSV exceeds 30,000 rows. Split it into smaller files.');
    }
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (quoted) {
            if (ch === '"') {
                if (text[i+1] === '"') { value += '"'; i++; }
                else { quoted = false; closed = true; }
            } else { value += ch; if (ch === '\n') line++; }
        } else if (ch === ',' ) { values.push(value); value = ''; closed = false; }
        else if (ch === '\n' || ch === '\r') {
            if (ch === '\r' && text[i+1] === '\n') i++;
            finish(); line++; startLine = line;
        } else if (ch === '"' && value.trim() === '' && !closed) { value = ''; quoted = true; }
        else if (closed && !/\s/.test(ch)) throw new Error(`Malformed CSV near line ${line}. Export a fresh CSV and try again.`);
        else if (!closed) value += ch;
    }
    if (quoted) throw new Error('CSV contains an unclosed quoted field. Export a fresh CSV and try again.');
    if (value || values.length) finish();
    return records;
}

function csvAmount(raw) {
    const cleaned = raw.trim().replace(/\u2212/g, '-').replace(/\s|\$/g, '');
    if (!/^(?:[+-]?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?|\((?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?\))$/.test(cleaned)) return null;
    const cents = Math.round(Number(cleaned.replace(/[(),+-]/g, '')) * 100);
    if (!Number.isSafeInteger(cents) || cents > 99999999999) return null;
    return {amount:(cents/100).toFixed(2), cents, negative:/^-|^\(/.test(cleaned)};
}

function csvDate(raw) {
    // Preserve the statement's calendar date, never convert timestamps to UTC.
    let date = '';
    const iso = raw.trim().match(/^(\d{4})-(\d{2})-(\d{2})(?=$|[T\s])/);
    const us = raw.trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4}|\d{2})(?=$|\s)/);
    if (iso) date = `${iso[1]}-${iso[2]}-${iso[3]}`;
    else if (us) date = `${us[3].length === 2 ? '20' : ''}${us[3]}-${us[1].padStart(2,'0')}-${us[2].padStart(2,'0')}`;
    const parsed = new Date(date + 'T12:00:00Z');
    return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0,10) === date ? date : '';
}

export function parseStatementCsv(text, month, mode = 'bank') {
    const records = readCsv(text);
    const normalize = value => value.trim().toLowerCase().replace(/\s+/g, ' ');
    let headerIndex = -1, headers = [], venmo = false;
    for (let i = 0; i < Math.min(records.length,100); i++) {
        const cells = records[i].values.map(normalize);
        const isVenmo = cells.includes('datetime') && cells.includes('amount (total)') && cells.includes('type');
        const generic = (cells.includes('date') || cells.includes('transaction date')) && cells.includes('description') && cells.includes('amount');
        if (isVenmo || generic) { headerIndex = i; headers = cells; venmo = isVenmo; break; }
    }
    if (headerIndex < 0) throw new Error('CSV headers not recognized. Use a Venmo statement export, or a CSV with Date, Description, and Amount columns.');
    const rows = [], skipped = [];
    for (const record of records.slice(headerIndex+1)) {
        const get = (...names) => {
            const index = headers.findIndex(header => names.includes(header));
            return index < 0 ? '' : (record.values[index] || '').trim();
        };
        const source = headers.map((header, index) => header && record.values[index] ? `${header}: ${record.values[index]}` : '').filter(Boolean).join(' | ');
        const skip = reason => skipped.push({line:record.line, text:source, reason});
        const rawDate = get('datetime','date','transaction date');
        const rawAmount = get('amount (total)','amount');
        if (!rawDate && !rawAmount) { skip('Non-transaction or summary row.'); continue; }
        const status = get('status').toLowerCase();
        const transactionType = get('type');
        if (venmo && status && !['complete','completed','settled','succeeded','successful'].includes(status)) { skip(`Transaction status is ${status}; not included.`); continue; }
        if (venmo && /transfer|cash\s*out|cash\s*in|add(?:ed)? (?:money|funds)|balance reload/i.test(transactionType)) { skip('Venmo balance/bank transfer excluded to avoid counting it as income or spending.'); continue; }
        const amount = csvAmount(rawAmount);
        if (!amount || amount.cents === 0) { skip('Missing, invalid, or zero amount.'); continue; }
        const date = csvDate(rawDate);
        if (date && !date.startsWith(month + '-')) { skip(`Outside audit month ${month} (${date}).`); continue; }
        const type = venmo || mode === 'bank' ? (amount.negative ? 'expense':'income') : (amount.negative ? 'income':'expense');
        const party = type === 'expense' ? get('to') : get('from');
        const name = venmo ? [get('note'), party ? `${type === 'expense' ? 'To' : 'From'} ${party}` : ''].filter(Boolean).join(' - ') || transactionType || 'Venmo transaction' : get('description');
        const notes = [];
        if (!date) notes.push('Date could not be read; enter it manually.');
        if (venmo) {
            notes.push('Venmo: negative is sent, positive is received. Check reimbursements and refunds before treating them as income.');
            if (!status) notes.push('No transaction status supplied; verify that this transaction completed.');
            const fee = get('amount (fee)');
            if (fee && (!csvAmount(fee) || csvAmount(fee).cents > 0)) notes.push(`Separate fee shown: ${fee}. Amount (total) is used without adding fees or tips again; review the total and record any separate fee if needed.`);
        }
        if (name.length > 240) notes.push('Description shortened to 240 characters; see the full source above.');
        rows.push({date, name:name.slice(0,240), amount:amount.amount, type, category:'uncategorized', source, sourceLabel:`CSV line ${record.line}`, notes});
    }
    return {rows, skipped, format:venmo ? 'Venmo CSV' : 'CSV'};
}

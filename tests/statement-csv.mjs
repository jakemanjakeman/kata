import assert from 'assert';
import {readCsv, parseStatementCsv} from '../app/statement-csv.mjs';
const csv = '\uFEFFAccount Statement - November 2026\r\nAccount Activity\r\n,ID,Datetime,Type,Status,Note,From,To,Amount (total),Amount (fee)\r\n' +
    ',1234567890123456789,2026-11-02T23:45:00,Payment,Complete,"Dinner, with friends",Me,Alex,- $25.50,$0.00\r\n' +
    ',2,2026-11-03T10:00:00,Payment,Complete,"Said ""thanks""\nfor lunch",Sam,Me,+ $12.00,$0.00\r\n' +
    ',3,2026-11-04T10:00:00,Standard Transfer,Complete,,Me,Bank,- $100.00,$0.00\r\n' +
    ',4,2026-11-05T10:00:00,Payment,Pending,Pending,Me,Sam,- $2.00,$0.00\r\n' +
    ',5,2026-11-06T10:00:00,Payment,Failed,Failed,Me,Sam,- $2.00,$0.00\r\n' +
    ',6,2026-10-31T23:59:00-05:00,Payment,Complete,Out of month,Sam,Me,+ $5.00,$0.00\r\n' +
    ',7,2026-11-07T10:00:00,Payment,Complete,Business sale,Sam,Me,"+ $1,200.00",$2.00\r\n' +
    ',8,2026-11-08T10:00:00,Payment,Complete,Invalid,Sam,Me,garbage,$0.00\r\n';
const parsed = parseStatementCsv(csv,'2026-11','card');
assert.strictEqual(parsed.format,'Venmo CSV');
assert.strictEqual(parsed.rows.length,3);
assert.strictEqual(parsed.skipped.length,5);
assert.strictEqual(parsed.rows[0].date,'2026-11-02');
assert.strictEqual(parsed.rows[0].amount,'25.50');
assert.strictEqual(parsed.rows[0].type,'expense');
assert.strictEqual(parsed.rows[0].name,'Dinner, with friends - To Alex');
assert(parsed.rows[0].source.includes('1234567890123456789'));
assert.strictEqual(parsed.rows[1].type,'income');
assert(parsed.rows[1].name.includes('Said "thanks"\nfor lunch'));
assert(parsed.skipped.some(row=>row.reason.includes('Outside audit month') && row.reason.includes('2026-10-31')));
assert.strictEqual(parsed.rows[2].amount,'1200.00');
assert(parsed.rows[2].notes.some(note=>note.includes('Separate fee')));
const boundaries=parseStatementCsv('Date,Description,Amount\n2028-01-31,Before,1\n2028-02-01,First,1\n2028-02-29,Last,1\n2028-03-01,After,1','2028-02');
assert.deepStrictEqual(boundaries.rows.map(row=>row.date),['2028-02-01','2028-02-29']);
assert.strictEqual(boundaries.skipped.length,2);
assert(parsed.skipped.some(row=>row.reason.includes('transfer')));
assert.throws(()=>readCsv('date,note\n"unclosed'),/unclosed/);
assert.throws(()=>readCsv('"closed"garbage,2'),/Malformed/);
assert.throws(()=>parseStatementCsv('Name,Total\nTest,2','2026-11'),/headers not recognized/);
const generic=parseStatementCsv('Date,Description,Amount\r11/03/2026,"Merchant, Inc.",(42.50)\r11/04/2026,Salary,2000\r','2026-11');
assert.strictEqual(generic.rows[0].amount,'42.50');
assert.strictEqual(generic.rows[0].type,'expense');
assert.strictEqual(generic.rows[1].type,'income');
assert.strictEqual(parseStatementCsv('Date,Description,Amount\n2026-11-04,Shop,10.00','2026-11','card').rows[0].type,'expense');
assert.strictEqual(parseStatementCsv('Date,Description,Amount\n2026-11-31,Shop,10.00','2026-11').rows[0].date,'');
console.log('CSV statement tests passed.');

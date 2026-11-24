import assert from 'assert';
import {parseStatement, statementLines} from '../app/statement-parser.mjs';

function line(text, page = 1) { return {text, page, cells:[{text, x:0, width:500}]}; }
const result = parseStatement([
    line('Statement balance $999.00'),
    line('11/02 11/03 GROCERY STORE 12.34'),
    line('Nov 4, 2026 CAFE 5.60'),
    line('2026-11-05 ONLINE PAYMENT -100.00'),
    line('11/06 REFUND 9.50 CR'),
    line('10/31 HOTEL 150.00'),
    line('11/31 INVALID DATE 20.00'),
    line('11/10 UNREADABLE AMOUNT'),
    line('11/12 MULTI COLUMN 25.00 475.00')
], '2026-11');
assert.strictEqual(result.rows.length, 6);
assert.strictEqual(result.rows[0].name, 'GROCERY STORE');
assert.strictEqual(result.rows[0].date, '2026-11-02');
assert.strictEqual(result.rows[0].amount, '12.34');
assert.strictEqual(result.rows[1].date, '2026-11-04');
assert.strictEqual(result.rows[2].type, 'income');
assert.strictEqual(result.rows[3].type, 'income');
assert(result.skipped.some(row => row.reason.includes('Outside audit month')));
assert.strictEqual(result.rows[4].date, '');
assert.strictEqual(result.skipped.length, 2);
assert(result.rows[5].notes.some(note => note.includes('Multiple amounts')));

const headers = {text:'Date Description Withdrawals Deposits Balance', page:1, cells:[{text:'Date',x:10,width:30},{text:'Description',x:80,width:70},{text:'Withdrawals',x:300,width:60},{text:'Deposits',x:400,width:60},{text:'Balance',x:500,width:60}]};
const debit = {text:'11/03 GROCERIES 45.00 955.00',page:1,cells:[{text:'11/03',x:10,width:30},{text:'GROCERIES',x:80,width:70},{text:'45.00',x:310,width:40},{text:'955.00',x:510,width:40}]};
const credit = {text:'11/04 PAYCHECK 1,000.00 1,955.00',page:1,cells:[{text:'11/04',x:10,width:30},{text:'PAYCHECK',x:80,width:70},{text:'1,000.00',x:400,width:60},{text:'1,955.00',x:500,width:60}]};
const table = parseStatement([headers,debit,credit], '2026-11', 'bank');
assert.strictEqual(table.rows[0].amount,'45.00');
assert.strictEqual(table.rows[0].type,'expense');
assert.strictEqual(table.rows[1].amount,'1000.00');
assert.strictEqual(table.rows[1].type,'income');
assert(parseStatement([line('12/31 SHOP 2.00')], '2027-01').skipped[0].reason.includes('2026-12-31'));
assert(parseStatement([line('01/01 SHOP 2.00')], '2026-12').skipped[0].reason.includes('2027-01-01'));
const boundaries = parseStatement(['10/31','11/01','11/30','12/01'].map(date=>line(date+' SHOP 2.00')), '2026-11');
assert.deepStrictEqual(boundaries.rows.map(row=>row.date), ['2026-11-01','2026-11-30']);
assert.strictEqual(boundaries.skipped.length, 2);
assert.strictEqual(parseStatement([line('11/01 SHOP (10.00)')], '2026-11','bank').rows[0].type,'expense');
assert.strictEqual(parseStatement([line('11/01 SHOP 10.00')], '2026-11','bank').rows[0].type,'income');

const extracted = statementLines([
    {str:'12.34',transform:[1,0,0,1,400,700],width:30},
    {str:'SHOP',transform:[1,0,0,1,100,700],width:40},
    {str:'11/01',transform:[1,0,0,1,20,701],width:30},
    {str:'11/02 NEXT 5.00',transform:[1,0,0,1,20,680],width:150}
], 2);
assert.strictEqual(extracted[0].text,'11/01 SHOP 12.34');
assert.strictEqual(extracted[1].page,2);
assert.strictEqual(parseStatement(extracted,'2026-11').rows.length,2);
console.log('Statement parser tests passed.');

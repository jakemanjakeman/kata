<?php
$importMaxVars = (int)ini_get('max_input_vars');
$importMaxRows = $importMaxVars > 0 ? max(1, min(1000, (int)floor(($importMaxVars - 20) / 6))) : 1000;
?>
<details class="statement-import" id="statement-import">
    <summary>Import statement PDF or CSV</summary>
    <p>Select a statement PDF or Venmo CSV. Review the suggested items and uncheck or remove any you do not want. The file stays in your browser; nothing is saved until you save the audit.</p>
    <div class="audit-item">
        <label>Statement PDF or CSV<input id="statement-file" type="file" accept="application/pdf,.pdf,text/csv,.csv"></label>
        <label>Account for these items<select id="statement-account"><option value="">Select account</option><?php foreach ($auditAccounts as $id => $name): ?><option value="<?= $auditEscape($id) ?>"><?= $auditEscape($name) ?></option><?php endforeach; ?></select></label>
        <label>Amount format (Venmo detected automatically)<select id="statement-mode"><option value="card">Credit card: purchases positive, credits negative</option><option value="bank">Bank: deposits positive, withdrawals negative</option></select></label>
        <button type="button" id="statement-parse">Preview statement</button>
    </div>
    <p>Separate debit/credit columns are used when detected. Check amounts, dates, and transaction types, especially payments, transfers, and refunds. Scanned or password-protected PDFs need an unlocked, text-based copy.</p>
    <p>Venmo CSVs use Amount (total), with money sent as expenses and money received as income for review. Bank transfers and incomplete payments are skipped. Other CSVs need Date, Description, and Amount columns; choose their amount format above.</p>
    <p id="statement-status" role="status" aria-live="polite"></p>
    <div id="statement-review" hidden>
        <p>Items start selected. Review the source line and fields, uncheck items to leave them out, or use &times; to remove them from the preview. Categories default to Uncategorized.</p>
        <div id="statement-rows"></div>
        <div class="audit-toolbar"><button type="button" id="statement-add">Add selected items to audit</button><button type="button" class="secondary-button" id="statement-discard">Discard preview</button></div>
    </div>
    <details id="statement-source" hidden><summary>Statement source / skipped rows</summary><pre id="statement-text"></pre></details>
</details>
<script type="application/json" id="statement-config"><?= json_encode(['month' => $auditMonth, 'categories' => $auditCategories, 'maxRows' => $importMaxRows], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="module" src="app/statement-import.mjs"></script>
<style>
.statement-import { margin:20px 0; padding:16px; border:1px solid var(--line); border-radius:8px; }
.statement-import summary { cursor:pointer; font-weight:800; }
.statement-import [hidden] { display:none!important; }
.statement-import .statement-row { margin:16px 0; padding:16px; border:1px solid var(--line); border-radius:6px; }
.statement-import .statement-row-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; }
.statement-import .statement-remove { flex:0 0 36px; width:36px; min-height:36px; padding:0; font-size:1.5rem; }
.statement-import .statement-select { position:static; display:flex; align-items:center; gap:8px; width:auto; height:auto; overflow:visible; clip:auto; font-weight:800; }
.statement-import .statement-select input { width:20px; height:20px; }
.statement-import .statement-source-line { overflow-wrap:anywhere; font-size:.9rem; color:var(--muted); }
.statement-import pre { white-space:pre-wrap; overflow-wrap:anywhere; max-height:320px; overflow:auto; }
.statement-import .statement-notes { font-size:.9rem; }
@media print { .statement-import { display:none; } }
</style>

<?php
$importMaxVars = (int)ini_get('max_input_vars');
$importMaxRows = $importMaxVars > 0 ? max(1, min(1000, (int)floor(($importMaxVars - 20) / 6))) : 1000;
?>
<details class="statement-import" id="statement-import">
    <summary>Import statement PDF</summary>
    <p>Select a text-based bank or credit-card statement. Review the suggested items and tick the ones you want to add. The PDF stays in your browser; nothing is saved until you save the audit.</p>
    <div class="audit-item">
        <label>Statement PDF<input id="statement-file" type="file" accept="application/pdf,.pdf"></label>
        <label>Account for these items<select id="statement-account"><option value="">Select account</option><?php foreach ($auditAccounts as $id => $name): ?><option value="<?= $auditEscape($id) ?>"><?= $auditEscape($name) ?></option><?php endforeach; ?></select></label>
        <label>Amount format<select id="statement-mode"><option value="card">Credit card: purchases positive, credits negative</option><option value="bank">Bank: deposits positive, withdrawals negative</option></select></label>
        <button type="button" id="statement-parse">Preview statement</button>
    </div>
    <p>Separate debit/credit columns are used when detected. Check amounts, dates, and transaction types, especially payments, transfers, and refunds. Scanned or password-protected PDFs need an unlocked, text-based copy.</p>
    <p id="statement-status" role="status" aria-live="polite"></p>
    <div id="statement-review" hidden>
        <p>Each item starts unchecked. Check the source line and edit any fields before selecting it. Categories default to Uncategorized.</p>
        <div id="statement-rows"></div>
        <div class="audit-toolbar"><button type="button" id="statement-add">Add selected items to audit</button><button type="button" class="secondary-button" id="statement-discard">Discard preview</button></div>
    </div>
    <details id="statement-source" hidden><summary>Extracted statement text / missed rows</summary><pre id="statement-text"></pre></details>
</details>
<script type="application/json" id="statement-config"><?= json_encode(['month' => $auditMonth, 'categories' => $auditCategories, 'maxRows' => $importMaxRows], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="module" src="app/statement-import.mjs"></script>
<style>
.statement-import { margin:20px 0; padding:16px; border:1px solid var(--line); border-radius:8px; }
.statement-import summary { cursor:pointer; font-weight:800; }
.statement-import [hidden] { display:none!important; }
.statement-import .statement-row { margin:16px 0; padding:16px; border:1px solid var(--line); border-radius:6px; }
.statement-import .statement-select { position:static; display:flex; align-items:center; gap:8px; width:auto; height:auto; overflow:visible; clip:auto; font-weight:800; }
.statement-import .statement-select input { width:20px; height:20px; }
.statement-import .statement-source-line { overflow-wrap:anywhere; font-size:.9rem; color:var(--muted); }
.statement-import pre { white-space:pre-wrap; overflow-wrap:anywhere; max-height:320px; overflow:auto; }
.statement-import .statement-notes { font-size:.9rem; }
@media print { .statement-import { display:none; } }
</style>

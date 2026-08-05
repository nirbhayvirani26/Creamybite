<?php
// ============================================================
//  Creamy Bite – Reports  (not built yet)
//
//  A nav link that 404s is worse than one that says plainly what is coming,
//  so this page exists and is honest rather than being hidden.
// ============================================================
require_once __DIR__ . '/_layout.php';
acctPageStart('reports', 'Reports', 'VAT and financial report builder');
?>
<div class="cbac-panel cbac-panel-note">
    <h2 class="cbac-panel-title">Not built yet</h2>
    <p>
        The schema for this is installed and the VAT return already reads from it —
        supplier bills entered here will flow straight into Box 4 and Box 7. The screen
        to enter and manage them is the next piece of work.
    </p>
    <p>
        In the meantime, anything you would record here can go in
        <a href="expenses.php">Expenses</a>, which posts to the ledger and the VAT return
        in exactly the same way.
    </p>
</div>
<?php acctPageEnd(); ?>

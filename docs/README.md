# Documents for inspection

## Creamy-Bite-Traceability-EHO.pdf

A description of how batch production and traceability work, and how a batch is
tied to the customer who received it, written for an Environmental Health
Officer. Five pages, printable, no system access needed to read it.

Regenerate it after any change to how production or traceability works:

```
python3 docs/generate_traceability_pdf.py docs/Creamy-Bite-Traceability-EHO.pdf
```

(Needs `reportlab`: `pip install reportlab`.)

**Keep it truthful.** Every statement in it was taken from the running code —
`includes/production.php`, `includes/traceability.php`, `admin/traceability.php`
and the `production_runs` / `order_batches` definitions in
`admin/migrations/update_db.php`. Section 9 states the limits of the system on
purpose: an inspector who finds one overstatement discounts everything else in
the document, so if a limit stops being true, change it there rather than
quietly dropping it.

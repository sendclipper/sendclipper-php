# Changelog

## 0.1.0 — 2026-10-16

First release.

- `emails->send`, `batch->send`, `emails->get`, `emails->list`, `emails->iterate`, `emails->stats`.
- Templates (with tags and image assets), domains, sandboxes (including the JSON door and `waitFor`), webhooks and their dispatches, contacts, audiences, suppressions, mailboxes, automations, workspace and API keys.
- Every method returns a `Result` with `data`, `error` and `headers`; it destructures to `[$data, $error]`.
- Automatic `Idempotency-Key` on every JSON write; retries on network errors, 429 and 5xx.
- `Webhook::verify()` for `X-SendClipper-Signature-V2`, rotation-aware.
- PHP 8.1 or newer; needs only ext-curl and ext-json.

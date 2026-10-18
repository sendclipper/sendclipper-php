# Changelog

## 0.2.0 — 2026-10-18

- `replyTo` on `emails->send`, `sandboxes->send` and each `batch->send` item: one address or a list of up to 10, sent as the `Reply-To` header. Bare addresses only.
- `attachments` on `emails->send` and `sandboxes->send`: a list of `['filename' => ..., 'content' => ..., 'contentType' => ...]` (`contentType` optional). `content` is the file's raw bytes, for example `file_get_contents('invoice.pdf')`, and the SDK always base64-encodes it. If you already hold base64, pass it as `contentBase64` instead and it is sent as given. An attachment with both, or neither, throws `InvalidArgumentException` before any request is made. Limits: 10 MiB per file, 25 MiB of files per message, 20 files. A batch item carrying `attachments` is refused by the API as `attachments_not_batchable`.
- Needs the SendClipper API from 2026-10-18 on; an older API ignores both fields.

## 0.1.0 — 2026-10-16

First release.

- `emails->send`, `batch->send`, `emails->get`, `emails->list`, `emails->iterate`, `emails->stats`.
- Templates (with tags and image assets), domains, sandboxes (including the JSON door and `waitFor`), webhooks and their dispatches, contacts, audiences, suppressions, mailboxes, automations, workspace and API keys.
- Every method returns a `Result` with `data`, `error` and `headers`; it destructures to `[$data, $error]`.
- Automatic `Idempotency-Key` on every JSON write; retries on network errors, 429 and 5xx.
- `Webhook::verify()` for `X-SendClipper-Signature-V2`, rotation-aware.
- PHP 8.1 or newer; needs only ext-curl and ext-json.

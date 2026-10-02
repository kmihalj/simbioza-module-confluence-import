# Changelog

## 0.1.42 - 2026-10-02

- Preserves dates stored in empty Confluence `time` elements as visible text
  before HTML sanitization, without changing existing labels or time zones.
- Resolves historical attachment binaries using the original logical attachment
  ID, while retaining support for exports using the historical record ID.
- Adds regression coverage for date preservation, safe attribute handling, and
  exact historical attachment version selection.

## 0.1.32 - 2026-09-16

- Normalizes test fixture paths for the current supported Rector release used
  by the release pipeline.

## 0.1.31 - 2026-09-16

- Records the original Confluence attachment creator and creation/update timestamps
  when importing attachments into the editor's version history.
- Resolves attachment references and audit data in import-time batches instead of
  consulting Confluence import tables during normal page rendering.
- Preserves attachment names and references consistently across imported pages and
  documents the separation between temporary import state and normal runtime data.

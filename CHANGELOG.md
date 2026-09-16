# Changelog

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

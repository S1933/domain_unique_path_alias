# Agent rules

Rules for AI agents and contributors working on this codebase.

## Structural rules

- Preserve core semantics.
- Fail closed when domain ownership is ambiguous.
- Do not use Request attributes as shared state.
- Prefer DI over `\Drupal::service()`.
- Test URL resolution over HTTP.
- Keep code comments to one line.

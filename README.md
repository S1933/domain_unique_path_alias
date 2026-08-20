# Domain unique path alias

Allows the same path alias to coexist on multiple domains, with each
domain only resolving the aliases it owns.

## Behavior

- The same alias (e.g. `/contact`) can exist on several domains and
  resolve to different nodes per domain.
- An alias belonging to one domain returns HTTP 404 when requested from
  another domain (fail-closed).
- Custom 404 pages are preserved: this module does not shortcut the
  routing layer.
- Language fallback mirrors Drupal core: requested langcode wins over
  `und`; disabled aliases (`status = 0`) are ignored.

## Domain ownership

- Nodes with a Domain Source value own the alias on that domain.
- Aliases with no domain_id whose source is not domainisable (e.g.
  `/user/...`) are global / neutral.
- Aliases with no domain_id whose source is domainisable resolve only
  on the source's domain; cross-domain access is rejected (legacy data
  fail-closed).
- Domain Source resolution is read on `path_alias_presave` and on
  `node_update`; no shared HTTP state is involved.

## Historical data

`hook_update_N()` (`domain_unique_path_alias_update_9001`) backfills
`domain_id` in batches of 50 and reports updated / neutral / unresolved
counters. Re-running it is idempotent.

## Cache context

Domain 3.x ships a `domain` cache context; when present, no further
configuration is needed. With Domain 2.x the module relies on
`url.site`. `hook_requirements()` warns when neither is available.

## Scope

- Entities: **nodes only** (Lot 10 — taxonomy terms are out of scope).
- Drupal core: `^10.2 || ^11`.
- Domain module: `^2.0 || ^3.0`.
- Pathauto: `^1.12`.

## Outbound URLs

Generating URLs that point at the alias-owning domain requires an
`OutboundPathProcessor`; this is a separate workstream (Lot 9 deferred).

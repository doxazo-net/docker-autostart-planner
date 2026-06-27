# Docker Autostart Planner - Phase 1 Design

Status: draft for review
Date: 2026-06-26
Target platform: Unraid 7.x (Docker, Community Applications ecosystem)

## Summary

Docker Autostart Planner is an advisory Unraid plugin that computes an optimal Docker
container autostart order and per-container start delays from dependency
information, then writes them into Unraid's native `unraid-autostart` file.
Unraid Docker performs all actual starting. The plugin's guiding principle:
**stack the deck, let Unraid deal.** It never participates in the boot path, so
if it is disabled, broken, or uninstalled, the last-stacked deck simply remains
in place and the boot is never worse off than before.

## Problem

Unraid CA-template users get only a flat, manually ordered autostart list with
fixed time delays. There is no dependency awareness (unlike Docker Compose's
`depends_on` + healthcheck conditions). In practice users set conservative,
guessed delays that are often inefficient and sometimes backwards (large delays
on leaf containers that nothing depends on, zero delay on the databases and
indexers that everything depends on). The result is slow, fragile boots that do
not reflect real dependency relationships or real readiness times.

Key facts about the mechanism (verified):

- The order/delay config lives at `/var/lib/docker/unraid-autostart`, one line
  per container, format `name [wait_seconds]`. A missing number means 0.
- The `wait` value is a post-start gate: after starting that container, Unraid
  waits N seconds before starting the **next** one in the list. It is a global
  serial gate, not a per-container init buffer.
- The file is on the persistent docker pool (not RAM), so direct edits survive
  reboots. `rc.docker` reads it at array start. `docker_init` preserves it
  verbatim (it only auto-disables a `Network=host` + Tailscale container). The
  Docker page GUI is the only other writer, and rewrites it (non-destructively)
  when the user changes autostart settings there.

## Goals

- Produce a correct, dependency-ordered autostart deck.
- Set delays from real measured readiness times, not guesses.
- Stay strictly advisory and non-invasive (never in the boot path).
- Be safe and reversible (backup before every write).
- Educate the user about what `wait` actually does.

## Non-goals (Phase 1)

- Community dependency mappings / sync (Phase 2).
- Connection/port watching (Phase 3, opt-in, off by default).
- Notifications (Phase 4).
- Health-gated or active startup (explicitly rejected: no hijacking startup).
- Package-level scanning. Dependencies come from config (and later, connections),
  not package data.
- Managing compose-managed containers, or any container not in
  `unraid-autostart`.

## Principles

- **Advisory only.** The plugin computes and writes the deck ahead of time;
  Unraid does the starting. Nothing new runs during array start.
- **Local reality wins.** Evidence from the user's actual container config
  always overrides external hints.
- **Privacy-respecting.** Out of the box the plugin only reads existing config.
  Any form of runtime "watching" is opt-in and off by default.
- **Graceful degradation.** Plugin off, broken, or on an unknown Unraid version
  falls back to read-only; the last applied deck persists.

## Architecture (Phase 1 components)

1. **Inventory reader** - enumerates containers in `unraid-autostart` and reads
   each container's config via `docker inspect`.
2. **Detector (config-only)** - derives dependency edges from local config:
   - `network_mode=container:X` -> hard dependency (certain).
   - Environment variables / config referencing other services (for example
     `*_HOST`, connection strings, `REDIS_URL`) -> service dependency.
   - `extra_hosts`, container links, shared custom-network membership.
   - Conservative common-sense heuristics for well-known stacks.
   This layer correctly distinguishes app variants: a "separate containers"
   install references an external DB in its config; an "all-in-one" install does
   not, so no external dependency is inferred.
3. **Metrics collector** - a cron-driven, read-only sampler that records each
   container's `StartedAt -> ready` time (healthcheck transition, listening
   port, or known log marker). Stores rolling samples and uses the median.
4. **Engine ("the dealer")** - builds a dependency DAG, detects cycles,
   topologically sorts it into a linear order (prerequisites front-loaded,
   leaves last), and assigns a delay only where a dependent immediately follows
   its prerequisite, sized to that prerequisite's measured readiness.
5. **Doctor** - lint rules that detect common autostart mistakes and offer
   one-click fixes through the Applier.
6. **Applier** - writes `unraid-autostart` atomically (temp file + rename),
   always backing up first; writes only `name [wait]` lines (no comments, to
   stay safe for `rc.docker`).
7. **State / drift tracker** - snapshots `unraid-autostart` and the last applied
   deck; detects external modification (GUI edits, new/removed containers) and
   surfaces a diff for reconciliation.
8. **UI** - a plugin page showing dependencies, measured times, proposed-vs-
   current deck, an apply action, a dependency editor, and an inline explainer
   of what `wait` does.

## Data model

All plugin data lives on flash so it survives a docker image rebuild:
`/boot/config/plugins/docker-autostart-planner/`

- `config.json` - plugin settings (apply mode, enabled features).
- `deps.json` - dependency edges: `{from, to, type: hard|soft, source:
  detected|user}`. (Phase 2 adds `source: preset`.)
- `metrics.json` - rolling per-container readiness samples and computed medians.
- `layout.json` - user-defined groupings and manual order overrides. These win
  over the computed order (subject to hard-dependency validation).
- `snapshots/` - timestamped captures of `unraid-autostart` and applied decks,
  for drift comparison and rollback.
- `backups/` - automatic, rotated backups of the plugin's own config files,
  taken before any modification, so a bad write or corrupted config is
  recoverable.

Precedence for resolving the dependency graph: user-declared overrides win over
detected config, which wins over common-sense heuristics. (Phase 2 community
mappings slot below detected config and are cross-checked against local reality;
Phase 3 connection-watching refines further when opted in.)

## Engine details

- Build a directed graph from dependency edges; each node is an autostart
  container.
- Detect cycles; if any exist, report the offending edges and refuse to produce
  a deck (do not silently break the loop).
- Topological sort (stable; deterministic tie-breaking) producing prerequisites
  first and leaves last.
- Delay assignment: front-loading prerequisites means most dependents get a
  natural head-start from the containers started between them, so explicit waits
  are minimized. Assign a wait only where a dependent sits immediately behind a
  prerequisite with insufficient natural spacing, sized to the prerequisite's
  measured median readiness (rounded up, small margin). Default to 0 when no
  measurement exists, with an optional fallback wait the user can enable.
- The computed order is a proposal. The user's manual layout (drag-reordering
  and groupings, stored in `layout.json`) overrides it and persists. Manual
  changes are validated against hard dependencies; the Doctor warns if a manual
  order would start a dependent before a hard prerequisite.

## Doctor rules (initial set)

Each rule detects, explains in plain language, and offers a one-click fix
applied through the backup-first Applier:

- Dependent ordered before its prerequisite.
- Large delay on a leaf container (nothing depends on it) - pure wasted boot
  time.
- Stale entry: a line with no matching container.
- A prerequisite with an adjacent dependent but no head-start.
- Duplicate entries.

## Apply and safety

- **Atomic writes.** Every file the plugin writes (`unraid-autostart` and its
  own configs) is written to a temp file in the same directory, fsynced, then
  atomically renamed into place, so an interrupted write can never leave a
  truncated or corrupt file.
- Always snapshot the current `unraid-autostart` to `snapshots/` before writing.
- **Automatic config backups.** Before modifying any of its own config files,
  the plugin backs them up to `backups/` (rotated), so a bad write or corruption
  is recoverable.
- Propose-and-confirm by default; an optional "auto-apply on drift" toggle.
- Write only `name [wait]` lines; never comments or unrecognized tokens.
- The plugin only ever writes ahead of time; it never runs during array start.
- Record each applied deck so drift can be detected by comparison.

## State and drift

- Snapshot `unraid-autostart` on apply and periodically.
- If the live file differs from the last applied deck (Docker page edit, new app
  install, container removal), flag drift, show the diff, and offer to
  reconcile or re-stack. This is the coexistence strategy with the native Docker
  page, which is the other writer of the file.

## Unraid integration and compatibility

- `.plg` manifest declares a tested Min and Max Unraid version. Outside that
  range the plugin runs read-only rather than writing an unverified format.
- Defensive parsing of `unraid-autostart`; an unrecognized format drops to
  read-only safe mode.
- Abstract Unraid paths that have changed across versions (for example the
  Docker manager plugin path moved from `dockerMan` to
  `dynamix.docker.manager` between 6 and 7).

## UI

Plugin page sections:

- Current deck, with a live drift indicator.
- Proposed deck, shown as a diff against current.
- Manual drag-to-reorder of the deck; the user's arrangement is respected,
  persisted (`layout.json`), and validated against hard dependencies.
- User-defined groupings: named groups of containers that can be collapsed and
  reordered as blocks, for organizing large container sets.
- Dependencies: detected and user-declared, editable.
- Measured readiness times per container.
- Doctor findings with one-click fixes.
- Apply action and settings.
- Inline "What does 'wait' do?" explainer.

## Tech stack

- Unraid plugin: `.plg` installer plus a PHP page under emhttp and backend
  scripts (PHP and/or bash).
- Data stored as JSON on flash.
- Minimize external dependencies.

## Roadmap (later phases, sketched so the architecture leaves room)

- **Phase 2** - community dependency mappings: versioned JSON schema, sync/
  update from a repo URL, cross-checked against local config, seed repo with the
  popular stacks. Popularity-prioritized using CA's own data so curation effort
  covers the most users. Mappings only (no shared timing).
- **Phase 3** - opt-in connection/port watching (off by default) to resolve
  service-class dependencies to concrete local containers and to auto-detect
  soft inter-app dependencies, refining the graph and metrics.
- **Phase 4** - notifications via Unraid's native notify system (opt-in):
  deck drift, detected problems, mapping updates pulled.

## Risks

- **Dependence on undocumented Unraid internals** (the autostart file format,
  `rc.docker`, plugin paths). Mitigation: version-gated manifest, defensive
  parsing, read-only fallback.
- **Co-writer conflict with the Docker page.** Mitigation: state capture and
  drift detection with reconcile.
- **Network-type complexity** (host and macvlan complicate later connection
  mapping). Mitigation: Phase 1 is config-only; bridge/custom-bridge are
  first-class, host/macvlan are best-effort and documented.

## Testing approach

- Unit tests for the engine: topological sort, cycle detection, delay
  assignment, against fixture graphs.
- Detection tests against fixture container configs (including variant cases:
  separate-DB vs all-in-one).
- Doctor rule tests against fixtures.
- Applier tests: backup is taken, output format is correct, operation is
  idempotent.
- Integration check on a real Unraid box: written file is read correctly by
  `rc.docker` at array start.

## Success criteria

On a real, complex install (the maintainer's ~60-container box), Autostart
Planner produces a correct dependency-ordered deck with minimal waits that
matches or beats the hand-tuned result, applies reversibly with a backup, and
detects drift when the native Docker page or an app install changes the file.

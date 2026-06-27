# Docker Autostart Planner - Claude Code Project Instructions

Plugin display name: **Docker Autostart Planner**. Repo slug stays kebab-case
`docker-autostart-planner`. PHP namespace: `DockerAutostartPlanner\` (PSR-4 -> `src/`).

## Project Overview

An advisory Unraid plugin that computes an optimal Docker container autostart
order and per-container start delays, then writes them into Unraid's native
`unraid-autostart` file. Unraid Docker performs all actual starting. Guiding
principle: **stack the deck, let Unraid deal.** If the plugin is disabled,
broken, or removed, the last-stacked deck simply remains; the boot path is never
touched.

Design and plans (read before implementing):
- Spec: [`docs/specs/2026-06-26-docker-autostart-planner-phase1-design.md`](docs/specs/2026-06-26-docker-autostart-planner-phase1-design.md)
- Plan A (core engine): [`docs/plans/2026-06-26-core-engine.md`](docs/plans/2026-06-26-core-engine.md)

## Style and Conventions

- **PHP 8.1+**, PSR-12, `declare(strict_types=1)` in every file. Tests: PHPUnit.
- Static analysis: **PHPStan** (level 6) + **PHP-CS-Fixer**. Security SAST:
  **Semgrep** (`p/php`, `p/security-audit`). No CodeQL (no PHP analyzer exists;
  CodeQL is reserved for the JS UI in Plan B once it lands).
- No emoji and no em-dashes in code, comments, or docs (use `-`, `,`, `()`).
- Pin GitHub Actions to commit SHAs (with `# vX`), job-level least-privilege
  `permissions:`, `persist-credentials: false` on checkout.

## Architecture (target)

```
src/                 - core engine (pure PHP, no Unraid/web deps; PSR-4)
  Model/             - Container, Edge value objects
  Detection/         - config-only dependency detector
  Engine/            - Graph, Planner (topo order + delays), Layout
  Metrics/           - readiness sample store
  Doctor/            - lint rules for common autostart mistakes
  Storage/           - AtomicWriter, JsonStore (rotated backups)
  State/             - drift detection
  Core.php           - facade wiring the above
plugin/              - Plan B: Unraid .plg tree (PHP *.page settings UI, rc.d, cron)
tests/               - PHPUnit
docs/specs, docs/plans
```

## Common Commands

```bash
make install   # composer install (dev deps)
make hooks     # enable .githooks
make test      # PHPUnit
make lint      # PHPStan
make check     # PHP-CS-Fixer dry-run
make fix       # auto-fix style
```

## Key Rules

- **Advisory only.** Never starts/stops containers, never runs in the boot path.
  It computes and writes files ahead of time; Unraid does the starting.
- **Atomic writes.** Every file write goes temp-file -> fsync -> rename. Back up
  config before modifying it.
- **Local reality wins.** Evidence from the user's actual container config beats
  any community hint. User-declared dependencies override detected ones.
- **Privacy-respecting.** Out of the box it only reads existing config. Any
  runtime connection/port "watching" (Plan B Phase 3) is opt-in, off by default.
- **unraid-autostart format:** `name` or `name <wait>`; no comments. The `wait`
  is a post-start gate before the NEXT container, not a per-container init buffer.

## PR Workflow

Follows the global workflow (`/prep-pr`, `/handle-review`, `/merge-pr`). CI runs
php-test, php-lint, and semgrep. Never push or open a PR without explicit
maintainer go-ahead.

## License

**MIT** (see [LICENSE](LICENSE)).

Licensing caveat: sibling repos **stillwater and canticle are GPL-3.0**.
Reimplement patterns in fresh code; do not paste their source into this MIT repo.
Reusing your own config/CI boilerplate between your own repos is fine.

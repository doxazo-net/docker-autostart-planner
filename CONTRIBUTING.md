# Contributing

Thanks for your interest in Docker Autostart Planner.

## Development setup

Requires PHP 8.1+ and Composer (see `.tool-versions` for the pinned dev version).

```bash
make install   # install dev dependencies
make hooks     # enable the git hooks (.githooks)
make test      # run the PHPUnit suite
make lint      # PHPStan static analysis
make check     # PHP-CS-Fixer style check (dry-run)
make fix       # auto-fix style
```

## Conventions

- PHP, PSR-12, `declare(strict_types=1)` in every file.
- Test-driven: write the failing test first, then the minimal code to pass.
- Run what CI runs before pushing: `make test && make lint && make check`.
  Semgrep (`p/php`, `p/security-audit`) also runs in CI.
- Conventional-commit-style messages and PR titles (`feat(planner): ...`,
  `fix(ci): ...`).
- GitHub Actions are pinned to commit SHAs (with a `# vX` comment).

## Workflow

- Branch from `main`; open a PR with at least one label set and `Closes #<issue>`.
- The implementation plans in `docs/plans/` are the task source of truth.

## License

By contributing you agree your contributions are licensed under the project's
MIT license (see `LICENSE`).

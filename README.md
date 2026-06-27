# Docker Autostart Planner

An advisory Unraid plugin that computes an optimal Docker container autostart
**order and delays** from real dependencies and measured startup times, then
writes them into Unraid's native autostart. Unraid Docker does the actual
starting. **Stack the deck, let Unraid deal.**

## Status

Pre-implementation. The design and the first implementation plan are committed:

- Design spec: [`docs/specs/2026-06-26-docker-autostart-planner-phase1-design.md`](docs/specs/2026-06-26-docker-autostart-planner-phase1-design.md)
- Plan A (core engine): [`docs/plans/2026-06-26-core-engine.md`](docs/plans/2026-06-26-core-engine.md)

## Why

Unraid CA-template users get only a flat ordered list with fixed time delays for
Docker autostart - no dependency awareness (unlike Compose's `depends_on`). The
usual workaround is conservative guessed delays that are slow and often wrong.
This plugin derives the order from actual dependencies (container config), sizes
delays from measured readiness, and lints the result - without ever taking over
the boot process.

## Development

Requires PHP 8.1+ and Composer. See [CONTRIBUTING.md](CONTRIBUTING.md).

```bash
make install && make hooks
make test
```

## License

[MIT](LICENSE)

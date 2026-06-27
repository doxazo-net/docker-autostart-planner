# Docker Autostart Planner - Core Engine Implementation Plan (Plan A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the pure-PHP core that turns container inventory + dependency edges + measured timings + user layout into an optimal `unraid-autostart` deck (order + delays), with a Doctor linter, atomic writes, rotated config backups, and drift detection. No Unraid/web dependencies; fully unit-testable off-box.

**Architecture:** A small set of focused PHP classes under one PSR-4 namespace. Detection produces dependency `Edge`s from container config; a `Graph` validates acyclicity; a `Planner` topologically orders containers and assigns delays from measured medians; a `Doctor` lints a deck; `AtomicWriter`/`JsonStore` persist safely; `Snapshotter` detects drift; a `Core` facade wires it together. Edge convention throughout: `Edge(from, to)` means "**from depends on to**" (so `to` is the prerequisite and must start before `from`).

**Tech Stack:** PHP 8.1+, Composer (PSR-4 autoload), PHPUnit 10. No runtime dependencies beyond PHP standard library.

## Global Constraints

- Language: PHP 8.1+. Tests: PHPUnit 10. No third-party runtime dependencies.
- Style: no emoji and no em-dashes in code, comments, or docs (use `-`, `,`, `()`).
- Advisory only: this core never starts/stops containers and never runs in the boot path. It only computes and writes files ahead of time.
- Atomic writes: every file write goes to a temp file in the same directory, is fsynced, then renamed into place.
- Config safety: before modifying any plugin config file, write a rotated backup first.
- Plugin data root (used by Plan B; the core takes paths as parameters): `/boot/config/plugins/docker-autostart-planner/`.
- `unraid-autostart` format: one line per container, `name` or `name <wait>` where wait is a non-negative integer seconds; no comments, no blank-line dependence.
- Edge semantics: `Edge(from, to)` = "from depends on to"; `to` must appear before `from` in the deck.
- Dependency precedence (Plan A scope): user-declared edges override detected edges. (Phase 2 adds preset between them.)

---

### Task 1: Project scaffold

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `src/.gitkeep`
- Create: `tests/SmokeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: PSR-4 autoload root `DockerAutostartPlanner\` -> `src/`; `composer test` runs PHPUnit.

- [ ] **Step 1: Write composer.json**

```json
{
    "name": "docker-autostart-planner/core",
    "description": "Core deck engine for the Docker Autostart Planner Unraid plugin",
    "type": "library",
    "require": {
        "php": ">=8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10"
    },
    "autoload": {
        "psr-4": {
            "DockerAutostartPlanner\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "DockerAutostartPlanner\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit"
    }
}
```

- [ ] **Step 2: Write phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="core">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Create src/.gitkeep and a smoke test**

`src/.gitkeep`: empty file.

`tests/SmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPhpunitRuns(): void
    {
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 4: Install and run**

Run: `composer install && composer test`
Expected: PHPUnit runs, 1 test, 1 assertion, OK.

- [ ] **Step 5: Commit**

```bash
printf '/vendor/\n/.phpunit.cache/\n' > .gitignore
git add composer.json phpunit.xml src/.gitkeep tests/SmokeTest.php .gitignore
git commit -m "chore: scaffold PHP core with composer and phpunit"
```

---

### Task 2: Domain model (Container and Edge)

**Files:**
- Create: `src/Model/Container.php`
- Create: `src/Model/Edge.php`
- Test: `tests/Model/EdgeTest.php`

**Interfaces:**
- Produces:
  - `Container` readonly: `__construct(string $name, string $networkMode = '', array $env = [], string $image = '')`; public readonly props `$name, $networkMode, $env, $image`. `$env` is `array<string,string>`.
  - `Edge` readonly: `__construct(string $from, string $to, string $type = 'soft', string $source = 'detected')`; props `$from, $to, $type, $source`. Helper `key(): string` returns `"{$from}\0{$to}"`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\Model;

use DockerAutostartPlanner\Model\Edge;
use PHPUnit\Framework\TestCase;

final class EdgeTest extends TestCase
{
    public function testStoresFieldsAndDefaults(): void
    {
        $e = new Edge('sonarr', 'prowlarr');
        $this->assertSame('sonarr', $e->from);
        $this->assertSame('prowlarr', $e->to);
        $this->assertSame('soft', $e->type);
        $this->assertSame('detected', $e->source);
    }

    public function testKeyIsUniquePerPair(): void
    {
        $a = new Edge('sonarr', 'prowlarr');
        $b = new Edge('sonarr', 'prowlarr', 'hard', 'user');
        $this->assertSame($a->key(), $b->key());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Model/EdgeTest.php`
Expected: FAIL ("Class ... Edge not found").

- [ ] **Step 3: Write minimal implementation**

`src/Model/Container.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Model;

final class Container
{
    /** @param array<string,string> $env */
    public function __construct(
        public readonly string $name,
        public readonly string $networkMode = '',
        public readonly array $env = [],
        public readonly string $image = '',
    ) {
    }
}
```

`src/Model/Edge.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Model;

final class Edge
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $type = 'soft',
        public readonly string $source = 'detected',
    ) {
    }

    public function key(): string
    {
        return $this->from . "\0" . $this->to;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Model/EdgeTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Model tests/Model
git commit -m "feat: add Container and Edge domain model"
```

---

### Task 3: Autostart file parse and serialize

**Files:**
- Create: `src/Apply/Entry.php`
- Create: `src/Apply/AutostartFile.php`
- Test: `tests/Apply/AutostartFileTest.php`

**Interfaces:**
- Produces:
  - `Entry` readonly: `__construct(string $name, int $wait = 0)`; props `$name, $wait`.
  - `AutostartFile::parse(string $text): array` -> list of `Entry` (skips blank lines; a line `name` -> wait 0; `name 8` -> wait 8).
  - `AutostartFile::serialize(array $entries): string` -> text, one `Entry` per line, `name` when wait is 0 else `name <wait>`, trailing newline.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\Apply;

use DockerAutostartPlanner\Apply\AutostartFile;
use DockerAutostartPlanner\Apply\Entry;
use PHPUnit\Framework\TestCase;

final class AutostartFileTest extends TestCase
{
    public function testParsesNamesAndWaits(): void
    {
        $entries = AutostartFile::parse("Redis\n\nsonarr 8\n");
        $this->assertCount(2, $entries);
        $this->assertSame('Redis', $entries[0]->name);
        $this->assertSame(0, $entries[0]->wait);
        $this->assertSame('sonarr', $entries[1]->name);
        $this->assertSame(8, $entries[1]->wait);
    }

    public function testSerializeOmitsZeroWait(): void
    {
        $text = AutostartFile::serialize([new Entry('Redis'), new Entry('sonarr', 8)]);
        $this->assertSame("Redis\nsonarr 8\n", $text);
    }

    public function testRoundTrip(): void
    {
        $text = "Redis\nprowlarr\nsonarr 8\n";
        $this->assertSame($text, AutostartFile::serialize(AutostartFile::parse($text)));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Apply/AutostartFileTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`src/Apply/Entry.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Apply;

final class Entry
{
    public function __construct(
        public readonly string $name,
        public readonly int $wait = 0,
    ) {
    }
}
```

`src/Apply/AutostartFile.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Apply;

final class AutostartFile
{
    /** @return list<Entry> */
    public static function parse(string $text): array
    {
        $entries = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            $name = $parts[0];
            $wait = (isset($parts[1]) && ctype_digit($parts[1])) ? (int) $parts[1] : 0;
            $entries[] = new Entry($name, $wait);
        }
        return $entries;
    }

    /** @param list<Entry> $entries */
    public static function serialize(array $entries): string
    {
        $out = '';
        foreach ($entries as $e) {
            $out .= $e->wait > 0 ? "{$e->name} {$e->wait}\n" : "{$e->name}\n";
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Apply/AutostartFileTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Apply tests/Apply
git commit -m "feat: parse and serialize the unraid-autostart format"
```

---

### Task 4: Dependency graph with cycle detection

**Files:**
- Create: `src/Engine/Graph.php`
- Test: `tests/Engine/GraphTest.php`

**Interfaces:**
- Consumes: `Edge` (Task 2).
- Produces:
  - `Graph::__construct(array $nodes, array $edges)` where `$nodes` is `list<string>` and `$edges` is `list<Edge>`.
  - `Graph::detectCycle(): ?array` returns a `list<string>` node path forming a cycle, or `null` if acyclic. Uses edge meaning from depends on to.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\Engine;

use DockerAutostartPlanner\Engine\Graph;
use DockerAutostartPlanner\Model\Edge;
use PHPUnit\Framework\TestCase;

final class GraphTest extends TestCase
{
    public function testAcyclicReturnsNull(): void
    {
        $g = new Graph(['a', 'b', 'c'], [new Edge('b', 'a'), new Edge('c', 'b')]);
        $this->assertNull($g->detectCycle());
    }

    public function testDetectsCycle(): void
    {
        $g = new Graph(['a', 'b'], [new Edge('a', 'b'), new Edge('b', 'a')]);
        $cycle = $g->detectCycle();
        $this->assertNotNull($cycle);
        $this->assertContains('a', $cycle);
        $this->assertContains('b', $cycle);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Engine/GraphTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`src/Engine/Graph.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Engine;

use DockerAutostartPlanner\Model\Edge;

final class Graph
{
    /** @var array<string,bool> */
    private array $nodes = [];
    /** @var array<string,list<string>> adjacency: prerequisite -> dependents */
    private array $adj = [];

    /**
     * @param list<string> $nodes
     * @param list<Edge> $edges
     */
    public function __construct(array $nodes, array $edges)
    {
        foreach ($nodes as $n) {
            $this->nodes[$n] = true;
            $this->adj[$n] = [];
        }
        foreach ($edges as $e) {
            // from depends on to: edge points to -> from (prereq -> dependent)
            if (isset($this->nodes[$e->to], $this->nodes[$e->from])) {
                $this->adj[$e->to][] = $e->from;
            }
        }
    }

    /** @return list<string>|null */
    public function detectCycle(): ?array
    {
        $state = [];
        $stack = [];
        foreach (array_keys($this->nodes) as $n) {
            $cycle = $this->visit($n, $state, $stack);
            if ($cycle !== null) {
                return $cycle;
            }
        }
        return null;
    }

    /**
     * @param array<string,int> $state 0=unvisited,1=in-stack,2=done
     * @param list<string> $stack
     * @return list<string>|null
     */
    private function visit(string $n, array &$state, array &$stack): ?array
    {
        $s = $state[$n] ?? 0;
        if ($s === 2) {
            return null;
        }
        if ($s === 1) {
            $idx = array_search($n, $stack, true);
            return array_values(array_slice($stack, (int) $idx));
        }
        $state[$n] = 1;
        $stack[] = $n;
        foreach ($this->adj[$n] as $next) {
            $cycle = $this->visit($next, $state, $stack);
            if ($cycle !== null) {
                return $cycle;
            }
        }
        array_pop($stack);
        $state[$n] = 2;
        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Engine/GraphTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Engine/Graph.php tests/Engine/GraphTest.php
git commit -m "feat: dependency graph with cycle detection"
```

---

### Task 5: Topological ordering

**Files:**
- Create: `src/Engine/Planner.php`
- Test: `tests/Engine/PlannerOrderTest.php`

**Interfaces:**
- Consumes: `Edge` (Task 2), `Graph` (Task 4).
- Produces:
  - `Planner::order(array $containers, array $edges): array` where `$containers` is `list<string>` (names in their current/input order, used for stable tie-breaking) and `$edges` is `list<Edge>`. Returns `list<string>` in start order: every prerequisite before its dependents; among independent nodes, original input order is preserved (stable). Throws `\RuntimeException` if a cycle exists.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\Engine;

use DockerAutostartPlanner\Engine\Planner;
use DockerAutostartPlanner\Model\Edge;
use PHPUnit\Framework\TestCase;

final class PlannerOrderTest extends TestCase
{
    public function testPrerequisitesComeFirst(): void
    {
        // sonarr depends on prowlarr; prowlarr depends on flaresolverr
        $order = Planner::order(
            ['sonarr', 'prowlarr', 'flaresolverr'],
            [new Edge('sonarr', 'prowlarr'), new Edge('prowlarr', 'flaresolverr')],
        );
        $this->assertSame(['flaresolverr', 'prowlarr', 'sonarr'], $order);
    }

    public function testStableForIndependentNodes(): void
    {
        $order = Planner::order(['a', 'b', 'c'], []);
        $this->assertSame(['a', 'b', 'c'], $order);
    }

    public function testThrowsOnCycle(): void
    {
        $this->expectException(\RuntimeException::class);
        Planner::order(['a', 'b'], [new Edge('a', 'b'), new Edge('b', 'a')]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Engine/PlannerOrderTest.php`
Expected: FAIL (class/method not found).

- [ ] **Step 3: Write minimal implementation**

`src/Engine/Planner.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Engine;

use DockerAutostartPlanner\Model\Edge;

final class Planner
{
    /**
     * @param list<string> $containers
     * @param list<Edge> $edges
     * @return list<string>
     */
    public static function order(array $containers, array $edges): array
    {
        $graph = new Graph($containers, $edges);
        if ($graph->detectCycle() !== null) {
            throw new \RuntimeException('Dependency cycle detected');
        }

        // indegree = number of prerequisites each node still waits on
        $indeg = array_fill_keys($containers, 0);
        /** @var array<string,list<string>> $deps prerequisite -> dependents */
        $deps = array_fill_keys($containers, []);
        $set = array_flip($containers);
        foreach ($edges as $e) {
            if (isset($set[$e->from], $set[$e->to])) {
                $indeg[$e->from]++;
                $deps[$e->to][] = $e->from;
            }
        }

        // Kahn's algorithm; ready queue kept in original input order for stability
        $ready = [];
        foreach ($containers as $n) {
            if ($indeg[$n] === 0) {
                $ready[] = $n;
            }
        }
        $order = [];
        while ($ready !== []) {
            $n = array_shift($ready);
            $order[] = $n;
            foreach ($deps[$n] as $d) {
                if (--$indeg[$d] === 0) {
                    // insert preserving original input order
                    self::insertStable($ready, $d, $containers);
                }
            }
        }
        return $order;
    }

    /**
     * @param list<string> $ready
     * @param list<string> $containers
     */
    private static function insertStable(array &$ready, string $node, array $containers): void
    {
        $rank = array_search($node, $containers, true);
        $i = 0;
        $count = count($ready);
        while ($i < $count && array_search($ready[$i], $containers, true) < $rank) {
            $i++;
        }
        array_splice($ready, $i, 0, [$node]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Engine/PlannerOrderTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Engine/Planner.php tests/Engine/PlannerOrderTest.php
git commit -m "feat: stable topological ordering with cycle guard"
```

---

### Task 6: Config-only detector

**Files:**
- Create: `src/Detection/Detector.php`
- Test: `tests/Detection/DetectorTest.php`

**Interfaces:**
- Consumes: `Container` (Task 2), `Edge` (Task 2).
- Produces:
  - `Detector::detect(array $containers): array` where `$containers` is `list<Container>`. Returns `list<Edge>` with `source: 'detected'`.
  - Rules: `networkMode` of form `container:<name>` -> hard edge `Edge(name, target, 'hard')`. Env values matching a known service host of another container -> soft edge. A container whose env references no other container yields no edge (handles the all-in-one variant).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\Detection;

use DockerAutostartPlanner\Detection\Detector;
use DockerAutostartPlanner\Model\Container;
use PHPUnit\Framework\TestCase;

final class DetectorTest extends TestCase
{
    public function testNetworkModeContainerIsHardDep(): void
    {
        $edges = Detector::detect([
            new Container('vpn'),
            new Container('app', 'container:vpn'),
        ]);
        $this->assertCount(1, $edges);
        $this->assertSame('app', $edges[0]->from);
        $this->assertSame('vpn', $edges[0]->to);
        $this->assertSame('hard', $edges[0]->type);
    }

    public function testEnvHostReferenceIsSoftDep(): void
    {
        $edges = Detector::detect([
            new Container('mariadb'),
            new Container('app', '', ['DB_HOST' => 'mariadb']),
        ]);
        $this->assertCount(1, $edges);
        $this->assertSame('app', $edges[0]->from);
        $this->assertSame('mariadb', $edges[0]->to);
        $this->assertSame('soft', $edges[0]->type);
    }

    public function testAllInOneVariantHasNoExternalDep(): void
    {
        // no env references another container -> no edges (bundled DB)
        $edges = Detector::detect([
            new Container('nextcloud-aio', '', ['SOME_FLAG' => 'true']),
        ]);
        $this->assertSame([], $edges);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Detection/DetectorTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`src/Detection/Detector.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Detection;

use DockerAutostartPlanner\Model\Container;
use DockerAutostartPlanner\Model\Edge;

final class Detector
{
    /**
     * @param list<Container> $containers
     * @return list<Edge>
     */
    public static function detect(array $containers): array
    {
        $names = [];
        foreach ($containers as $c) {
            $names[$c->name] = true;
        }

        $edges = [];
        $seen = [];
        foreach ($containers as $c) {
            // hard: network_mode=container:<name>
            if (str_starts_with($c->networkMode, 'container:')) {
                $target = substr($c->networkMode, strlen('container:'));
                if (isset($names[$target]) && $target !== $c->name) {
                    $edges[] = self::add($seen, new Edge($c->name, $target, 'hard'));
                }
            }
            // soft: any env value that exactly names another container
            foreach ($c->env as $value) {
                if (isset($names[$value]) && $value !== $c->name) {
                    $e = self::add($seen, new Edge($c->name, $value, 'soft'));
                    if ($e !== null) {
                        $edges[] = $e;
                    }
                }
            }
        }
        return array_values(array_filter($edges));
    }

    /**
     * @param array<string,bool> $seen
     */
    private static function add(array &$seen, Edge $e): ?Edge
    {
        if (isset($seen[$e->key()])) {
            return null;
        }
        $seen[$e->key()] = true;
        return $e;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Detection/DetectorTest.php`
Expected: PASS (3 tests). Note: `array_filter` drops the nulls from the hard-edge branch; the hard branch pushes the Edge directly, the soft branch guards nulls, so all elements are Edge or filtered.

- [ ] **Step 5: Commit**

```bash
git add src/Detection tests/Detection
git commit -m "feat: config-only dependency detector (network_mode + env refs)"
```

---

### Task 7: Metrics store

**Files:**
- Create: `src/Metrics/MetricsStore.php`
- Test: `tests/Metrics/MetricsStoreTest.php`

**Interfaces:**
- Produces:
  - `MetricsStore::__construct(array $samples = [])` where `$samples` is `array<string, list<int>>` (container name -> readiness seconds samples).
  - `MetricsStore::add(string $name, int $seconds): void`.
  - `MetricsStore::median(string $name): ?int` returns rounded-up median, or `null` if no samples.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\Metrics;

use DockerAutostartPlanner\Metrics\MetricsStore;
use PHPUnit\Framework\TestCase;

final class MetricsStoreTest extends TestCase
{
    public function testMedianOfOdd(): void
    {
        $m = new MetricsStore(['prowlarr' => [4, 5, 6]]);
        $this->assertSame(5, $m->median('prowlarr'));
    }

    public function testMedianOfEvenRoundsUp(): void
    {
        $m = new MetricsStore(['jackett' => [6, 9]]);
        $this->assertSame(8, $m->median('jackett'));
    }

    public function testUnknownReturnsNull(): void
    {
        $this->assertNull((new MetricsStore())->median('nope'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Metrics/MetricsStoreTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`src/Metrics/MetricsStore.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Metrics;

final class MetricsStore
{
    /** @var array<string,list<int>> */
    private array $samples;

    /** @param array<string,list<int>> $samples */
    public function __construct(array $samples = [])
    {
        $this->samples = $samples;
    }

    public function add(string $name, int $seconds): void
    {
        $this->samples[$name][] = $seconds;
    }

    public function median(string $name): ?int
    {
        $vals = $this->samples[$name] ?? [];
        if ($vals === []) {
            return null;
        }
        sort($vals);
        $n = count($vals);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return $vals[$mid];
        }
        return (int) ceil(($vals[$mid - 1] + $vals[$mid]) / 2);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Metrics/MetricsStoreTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Metrics tests/Metrics
git commit -m "feat: metrics store with median readiness"
```

---

### Task 8: Delay assignment (build the Deck)

**Files:**
- Modify: `src/Engine/Planner.php`
- Test: `tests/Engine/PlannerDelayTest.php`

**Interfaces:**
- Consumes: `Edge` (Task 2), `MetricsStore` (Task 7), `Entry` (Task 3), `Planner::order` (Task 5).
- Produces:
  - `Planner::buildDeck(array $containers, array $edges, MetricsStore $metrics, int $defaultWait = 0): array` returns `list<Entry>` in start order. A wait is assigned to the Entry at position i only when the very next container in the order (position i+1) directly depends on it (there is an Edge from next to current); the wait is `metrics->median(current)` if known, else `$defaultWait`. Otherwise wait is 0.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\Engine;

use DockerAutostartPlanner\Engine\Planner;
use DockerAutostartPlanner\Metrics\MetricsStore;
use DockerAutostartPlanner\Model\Edge;
use PHPUnit\Framework\TestCase;

final class PlannerDelayTest extends TestCase
{
    public function testWaitAssignedWhenNextDependsOnCurrent(): void
    {
        // tdarr_node depends on tdarr; tdarr ready ~10s
        $metrics = new MetricsStore(['tdarr' => [10]]);
        $deck = Planner::buildDeck(
            ['tdarr', 'tdarr_node'],
            [new Edge('tdarr_node', 'tdarr')],
            $metrics,
        );
        $this->assertSame('tdarr', $deck[0]->name);
        $this->assertSame(10, $deck[0]->wait);
        $this->assertSame('tdarr_node', $deck[1]->name);
        $this->assertSame(0, $deck[1]->wait);
    }

    public function testNoWaitWhenNextIsIndependent(): void
    {
        $metrics = new MetricsStore(['tdarr' => [10]]);
        $deck = Planner::buildDeck(
            ['tdarr', 'unrelated'],
            [],
            $metrics,
        );
        $this->assertSame(0, $deck[0]->wait);
        $this->assertSame(0, $deck[1]->wait);
    }

    public function testFallsBackToDefaultWaitWhenNoMetric(): void
    {
        $deck = Planner::buildDeck(
            ['tdarr', 'tdarr_node'],
            [new Edge('tdarr_node', 'tdarr')],
            new MetricsStore(),
            5,
        );
        $this->assertSame(5, $deck[0]->wait);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Engine/PlannerDelayTest.php`
Expected: FAIL (method not found).

- [ ] **Step 3: Add buildDeck to Planner**

Append this method inside the `Planner` class in `src/Engine/Planner.php` (after `order`), and add the import at the top of the file:

At the top, under the existing `use`:

```php
use DockerAutostartPlanner\Apply\Entry;
use DockerAutostartPlanner\Metrics\MetricsStore;
```

Method:

```php
    /**
     * @param list<string> $containers
     * @param list<Edge> $edges
     * @return list<Entry>
     */
    public static function buildDeck(
        array $containers,
        array $edges,
        MetricsStore $metrics,
        int $defaultWait = 0,
    ): array {
        $order = self::order($containers, $edges);

        // dependsOn[from][to] = true
        $dependsOn = [];
        foreach ($edges as $e) {
            $dependsOn[$e->from][$e->to] = true;
        }

        $deck = [];
        $count = count($order);
        foreach ($order as $i => $name) {
            $wait = 0;
            if ($i + 1 < $count) {
                $next = $order[$i + 1];
                if (isset($dependsOn[$next][$name])) {
                    $median = $metrics->median($name);
                    $wait = $median ?? $defaultWait;
                }
            }
            $deck[] = new Entry($name, $wait);
        }
        return $deck;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Engine/PlannerDelayTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Engine/Planner.php tests/Engine/PlannerDelayTest.php
git commit -m "feat: assign measured delays and build the deck"
```

---

### Task 9: Doctor (lint rules)

**Files:**
- Create: `src/Doctor/Finding.php`
- Create: `src/Doctor/Doctor.php`
- Test: `tests/Doctor/DoctorTest.php`

**Interfaces:**
- Consumes: `Entry` (Task 3), `Edge` (Task 2).
- Produces:
  - `Finding` readonly: `__construct(string $rule, string $severity, string $message)`; props `$rule, $severity, $message`. Severity is `'warning'` or `'info'`.
  - `Doctor::check(array $entries, array $edges, array $existingContainers): array` returns `list<Finding>`. Rules:
    - `dependent_before_prereq` (warning): an entry depends on another entry that appears later in the list.
    - `fat_leaf_wait` (info): an entry has wait > 0 but nothing depends on it.
    - `stale_entry` (warning): an entry name not present in `$existingContainers` (a `list<string>`).
    - `duplicate_entry` (warning): the same name appears more than once.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\Doctor;

use DockerAutostartPlanner\Apply\Entry;
use DockerAutostartPlanner\Doctor\Doctor;
use DockerAutostartPlanner\Model\Edge;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase
{
    public function testDependentBeforePrereq(): void
    {
        // sonarr depends on prowlarr but is listed first
        $findings = Doctor::check(
            [new Entry('sonarr'), new Entry('prowlarr')],
            [new Edge('sonarr', 'prowlarr')],
            ['sonarr', 'prowlarr'],
        );
        $this->assertSame('dependent_before_prereq', $this->rules($findings)[0]);
    }

    public function testFatLeafWait(): void
    {
        $findings = Doctor::check(
            [new Entry('makemkv', 60)],
            [],
            ['makemkv'],
        );
        $this->assertContains('fat_leaf_wait', $this->rules($findings));
    }

    public function testStaleEntry(): void
    {
        $findings = Doctor::check([new Entry('ghost')], [], []);
        $this->assertContains('stale_entry', $this->rules($findings));
    }

    public function testDuplicateEntry(): void
    {
        $findings = Doctor::check(
            [new Entry('a'), new Entry('a')],
            [],
            ['a'],
        );
        $this->assertContains('duplicate_entry', $this->rules($findings));
    }

    /**
     * @param list<\DockerAutostartPlanner\Doctor\Finding> $findings
     * @return list<string>
     */
    private function rules(array $findings): array
    {
        return array_values(array_map(static fn ($f) => $f->rule, $findings));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Doctor/DoctorTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`src/Doctor/Finding.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Doctor;

final class Finding
{
    public function __construct(
        public readonly string $rule,
        public readonly string $severity,
        public readonly string $message,
    ) {
    }
}
```

`src/Doctor/Doctor.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Doctor;

use DockerAutostartPlanner\Apply\Entry;
use DockerAutostartPlanner\Model\Edge;

final class Doctor
{
    /**
     * @param list<Entry> $entries
     * @param list<Edge> $edges
     * @param list<string> $existingContainers
     * @return list<Finding>
     */
    public static function check(array $entries, array $edges, array $existingContainers): array
    {
        $pos = [];
        foreach ($entries as $i => $e) {
            $pos[$e->name] ??= $i;
        }
        $hasDependents = [];
        foreach ($edges as $e) {
            $hasDependents[$e->to] = true;
        }
        $existing = array_flip($existingContainers);

        $findings = [];

        foreach ($edges as $e) {
            if (isset($pos[$e->from], $pos[$e->to]) && $pos[$e->to] > $pos[$e->from]) {
                $findings[] = new Finding(
                    'dependent_before_prereq',
                    'warning',
                    "{$e->from} starts before its prerequisite {$e->to}",
                );
            }
        }

        $counts = [];
        foreach ($entries as $e) {
            $counts[$e->name] = ($counts[$e->name] ?? 0) + 1;
            if ($e->wait > 0 && !isset($hasDependents[$e->name])) {
                $findings[] = new Finding(
                    'fat_leaf_wait',
                    'info',
                    "{$e->name} has a {$e->wait}s wait but nothing depends on it",
                );
            }
            if (!isset($existing[$e->name])) {
                $findings[] = new Finding(
                    'stale_entry',
                    'warning',
                    "{$e->name} is in autostart but no such container exists",
                );
            }
        }
        foreach ($counts as $name => $count) {
            if ($count > 1) {
                $findings[] = new Finding(
                    'duplicate_entry',
                    'warning',
                    "{$name} appears {$count} times in autostart",
                );
            }
        }

        return $findings;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Doctor/DoctorTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Doctor tests/Doctor
git commit -m "feat: Doctor lint rules for common autostart mistakes"
```

---

### Task 10: Atomic writer

**Files:**
- Create: `src/Storage/AtomicWriter.php`
- Test: `tests/Storage/AtomicWriterTest.php`

**Interfaces:**
- Produces:
  - `AtomicWriter::write(string $path, string $contents): void` writes to a temp file in the same directory, fsyncs, then renames over `$path`. Throws `\RuntimeException` on failure. Creates the parent directory if missing.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\Storage;

use DockerAutostartPlanner\Storage\AtomicWriter;
use PHPUnit\Framework\TestCase;

final class AtomicWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dap_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testWritesContent(): void
    {
        $path = $this->dir . '/out.txt';
        AtomicWriter::write($path, "hello\n");
        $this->assertSame("hello\n", file_get_contents($path));
    }

    public function testLeavesNoTempArtifacts(): void
    {
        $path = $this->dir . '/out.txt';
        AtomicWriter::write($path, "data");
        $files = array_map('basename', glob($this->dir . '/*') ?: []);
        $this->assertSame(['out.txt'], $files);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Storage/AtomicWriterTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`src/Storage/AtomicWriter.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Storage;

final class AtomicWriter
{
    public static function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }
        $tmp = $dir . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(4));
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open temp file: {$tmp}");
        }
        try {
            if (fwrite($fh, $contents) === false) {
                throw new \RuntimeException("Write failed: {$tmp}");
            }
            fflush($fh);
            // fsync is available on PHP 8.1+
            if (function_exists('fsync')) {
                @fsync($fh);
            }
        } finally {
            fclose($fh);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Rename failed: {$tmp} -> {$path}");
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Storage/AtomicWriterTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Storage/AtomicWriter.php tests/Storage/AtomicWriterTest.php
git commit -m "feat: atomic file writer (temp + fsync + rename)"
```

---

### Task 11: JSON store with rotated backups

**Files:**
- Create: `src/Storage/JsonStore.php`
- Test: `tests/Storage/JsonStoreTest.php`

**Interfaces:**
- Consumes: `AtomicWriter` (Task 10).
- Produces:
  - `JsonStore::__construct(string $baseDir, int $keepBackups = 5)`.
  - `JsonStore::read(string $name): array` returns decoded array, or `[]` if the file does not exist.
  - `JsonStore::write(string $name, array $data): void` backs up the existing file (if any) to `backups/<name>.<timestamp>` keeping the most recent `$keepBackups`, then atomically writes the new JSON. The timestamp is supplied by an injectable clock to keep tests deterministic: `JsonStore::write(string $name, array $data, ?int $now = null)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\Storage;

use DockerAutostartPlanner\Storage\JsonStore;
use PHPUnit\Framework\TestCase;

final class JsonStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dapjs_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    public function testReadMissingReturnsEmpty(): void
    {
        $store = new JsonStore($this->dir);
        $this->assertSame([], $store->read('config'));
    }

    public function testWriteThenRead(): void
    {
        $store = new JsonStore($this->dir);
        $store->write('config', ['a' => 1], 1000);
        $this->assertSame(['a' => 1], $store->read('config'));
    }

    public function testWriteBacksUpPrevious(): void
    {
        $store = new JsonStore($this->dir);
        $store->write('config', ['v' => 1], 1000);
        $store->write('config', ['v' => 2], 1001);
        $backups = glob($this->dir . '/backups/config.*') ?: [];
        $this->assertCount(1, $backups);
        $this->assertSame(['v' => 1], json_decode((string) file_get_contents($backups[0]), true));
    }

    private function rrmdir(string $d): void
    {
        foreach (glob($d . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : unlink($f);
        }
        @rmdir($d);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Storage/JsonStoreTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`src/Storage/JsonStore.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Storage;

final class JsonStore
{
    public function __construct(
        private readonly string $baseDir,
        private readonly int $keepBackups = 5,
    ) {
    }

    /** @return array<mixed> */
    public function read(string $name): array
    {
        $path = "{$this->baseDir}/{$name}.json";
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<mixed> $data */
    public function write(string $name, array $data, ?int $now = null): void
    {
        $path = "{$this->baseDir}/{$name}.json";
        if (is_file($path)) {
            $ts = $now ?? time();
            $backupDir = "{$this->baseDir}/backups";
            AtomicWriter::write("{$backupDir}/{$name}.{$ts}", (string) file_get_contents($path));
            $this->rotate($backupDir, $name);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        AtomicWriter::write($path, $json . "\n");
    }

    private function rotate(string $backupDir, string $name): void
    {
        $backups = glob("{$backupDir}/{$name}.*") ?: [];
        sort($backups);
        while (count($backups) > $this->keepBackups) {
            $oldest = array_shift($backups);
            @unlink((string) $oldest);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Storage/JsonStoreTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Storage/JsonStore.php tests/Storage/JsonStoreTest.php
git commit -m "feat: JSON store with atomic writes and rotated backups"
```

---

### Task 12: Layout overrides

**Files:**
- Create: `src/Engine/Layout.php`
- Test: `tests/Engine/LayoutTest.php`

**Interfaces:**
- Consumes: `Edge` (Task 2).
- Produces:
  - `Layout::apply(array $computedOrder, array $userOrder, array $hardEdges): array` returns `['order' => list<string>, 'violations' => list<string>]`. `$userOrder` is the user's manual order (a `list<string>`; names not present are appended in computed order). The result order is the user order followed by any computed-order names the user omitted. `violations` lists messages where the user order places a dependent before a hard prerequisite (`$hardEdges` is `list<Edge>` of type hard).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\Engine;

use DockerAutostartPlanner\Engine\Layout;
use DockerAutostartPlanner\Model\Edge;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    public function testUserOrderWinsAndAppendsOmitted(): void
    {
        $res = Layout::apply(['a', 'b', 'c'], ['c', 'a'], []);
        $this->assertSame(['c', 'a', 'b'], $res['order']);
        $this->assertSame([], $res['violations']);
    }

    public function testFlagsHardDepViolation(): void
    {
        // app depends (hard) on vpn, but user puts app before vpn
        $res = Layout::apply(['vpn', 'app'], ['app', 'vpn'], [new Edge('app', 'vpn', 'hard')]);
        $this->assertCount(1, $res['violations']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Engine/LayoutTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`src/Engine/Layout.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Engine;

use DockerAutostartPlanner\Model\Edge;

final class Layout
{
    /**
     * @param list<string> $computedOrder
     * @param list<string> $userOrder
     * @param list<Edge> $hardEdges
     * @return array{order: list<string>, violations: list<string>}
     */
    public static function apply(array $computedOrder, array $userOrder, array $hardEdges): array
    {
        $seen = array_flip($userOrder);
        $order = $userOrder;
        foreach ($computedOrder as $name) {
            if (!isset($seen[$name])) {
                $order[] = $name;
            }
        }

        $pos = array_flip($order);
        $violations = [];
        foreach ($hardEdges as $e) {
            if (isset($pos[$e->from], $pos[$e->to]) && $pos[$e->to] > $pos[$e->from]) {
                $violations[] = "{$e->from} is ordered before its hard prerequisite {$e->to}";
            }
        }

        return ['order' => array_values($order), 'violations' => $violations];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Engine/LayoutTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Engine/Layout.php tests/Engine/LayoutTest.php
git commit -m "feat: user layout overrides with hard-dependency validation"
```

---

### Task 13: Drift detection

**Files:**
- Create: `src/State/Drift.php`
- Test: `tests/State/DriftTest.php`

**Interfaces:**
- Consumes: nothing (operates on strings).
- Produces:
  - `Drift::detect(string $lastAppliedText, string $currentText): array` returns `['changed' => bool, 'summary' => list<string>]`, comparing the two `unraid-autostart` file contents line-by-line (trimmed, blank lines ignored). `summary` lists human-readable add/remove/move notes.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests\State;

use DockerAutostartPlanner\State\Drift;
use PHPUnit\Framework\TestCase;

final class DriftTest extends TestCase
{
    public function testNoChange(): void
    {
        $res = Drift::detect("a\nb\n", "a\nb\n");
        $this->assertFalse($res['changed']);
        $this->assertSame([], $res['summary']);
    }

    public function testDetectsAddAndRemove(): void
    {
        $res = Drift::detect("a\nb\n", "a\nc\n");
        $this->assertTrue($res['changed']);
        $this->assertNotEmpty($res['summary']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/State/DriftTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`src/State/Drift.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\State;

final class Drift
{
    /**
     * @return array{changed: bool, summary: list<string>}
     */
    public static function detect(string $lastAppliedText, string $currentText): array
    {
        $a = self::lines($lastAppliedText);
        $b = self::lines($currentText);

        $summary = [];
        foreach (array_diff($b, $a) as $added) {
            $summary[] = "added: {$added}";
        }
        foreach (array_diff($a, $b) as $removed) {
            $summary[] = "removed: {$removed}";
        }
        if ($summary === [] && $a !== $b) {
            $summary[] = 'order changed';
        }

        return ['changed' => $a !== $b, 'summary' => array_values($summary)];
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/State/DriftTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/State tests/State
git commit -m "feat: autostart drift detection"
```

---

### Task 14: Core facade and integration test

**Files:**
- Create: `src/Core.php`
- Test: `tests/CoreIntegrationTest.php`

**Interfaces:**
- Consumes: all prior tasks.
- Produces:
  - `Core::plan(array $containers, array $userEdges, MetricsStore $metrics, int $defaultWait = 0): array` returns `['text' => string, 'deck' => list<Entry>, 'findings' => list<Finding>]`. It runs `Detector::detect`, merges user edges with detected edges (user overrides: a user Edge with the same key replaces a detected one), builds the deck via `Planner::buildDeck`, serializes to `unraid-autostart` text, and runs `Doctor::check` on the result.

- [ ] **Step 1: Write the failing integration test**

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner\Tests;

use DockerAutostartPlanner\Core;
use DockerAutostartPlanner\Metrics\MetricsStore;
use DockerAutostartPlanner\Model\Container;
use DockerAutostartPlanner\Model\Edge;
use PHPUnit\Framework\TestCase;

final class CoreIntegrationTest extends TestCase
{
    public function testProducesOrderedDeckWithPrereqsFirst(): void
    {
        $containers = [
            new Container('sonarr', '', ['PROWLARR' => 'prowlarr']),
            new Container('prowlarr'),
            new Container('vpnapp', 'container:vpn'),
            new Container('vpn'),
        ];
        $result = Core::plan($containers, [], new MetricsStore());

        $names = array_map(static fn ($e) => $e->name, $result['deck']);
        $this->assertLessThan(
            array_search('sonarr', $names, true),
            array_search('prowlarr', $names, true),
        );
        $this->assertLessThan(
            array_search('vpnapp', $names, true),
            array_search('vpn', $names, true),
        );
        $this->assertStringEndsWith("\n", $result['text']);
        $this->assertSame([], $result['findings']);
    }

    public function testUserEdgeOverridesDetected(): void
    {
        // user declares a soft dep the detector cannot see
        $containers = [new Container('bazarr'), new Container('sonarr')];
        $result = Core::plan(
            $containers,
            [new Edge('bazarr', 'sonarr', 'soft', 'user')],
            new MetricsStore(),
        );
        $names = array_map(static fn ($e) => $e->name, $result['deck']);
        $this->assertLessThan(
            array_search('bazarr', $names, true),
            array_search('sonarr', $names, true),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/CoreIntegrationTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`src/Core.php`:

```php
<?php

declare(strict_types=1);

namespace DockerAutostartPlanner;

use DockerAutostartPlanner\Apply\AutostartFile;
use DockerAutostartPlanner\Detection\Detector;
use DockerAutostartPlanner\Doctor\Doctor;
use DockerAutostartPlanner\Engine\Planner;
use DockerAutostartPlanner\Metrics\MetricsStore;
use DockerAutostartPlanner\Model\Container;
use DockerAutostartPlanner\Model\Edge;

final class Core
{
    /**
     * @param list<Container> $containers
     * @param list<Edge> $userEdges
     * @return array{text: string, deck: list<\DockerAutostartPlanner\Apply\Entry>, findings: list<\DockerAutostartPlanner\Doctor\Finding>}
     */
    public static function plan(
        array $containers,
        array $userEdges,
        MetricsStore $metrics,
        int $defaultWait = 0,
    ): array {
        $detected = Detector::detect($containers);

        // merge: user edges override detected edges with the same key
        $byKey = [];
        foreach ($detected as $e) {
            $byKey[$e->key()] = $e;
        }
        foreach ($userEdges as $e) {
            $byKey[$e->key()] = $e;
        }
        $edges = array_values($byKey);

        $names = array_map(static fn (Container $c) => $c->name, $containers);
        $deck = Planner::buildDeck($names, $edges, $metrics, $defaultWait);
        $text = AutostartFile::serialize($deck);
        $findings = Doctor::check($deck, $edges, $names);

        return ['text' => $text, 'deck' => $deck, 'findings' => $findings];
    }
}
```

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: ALL tests pass across every suite.

- [ ] **Step 5: Commit**

```bash
git add src/Core.php tests/CoreIntegrationTest.php
git commit -m "feat: Core facade wiring detection, planning, and doctor"
```

---

## Self-Review

**Spec coverage (Plan A scope):**
- Inventory model -> Task 2. Config-only detection (network_mode, env refs, variant handling) -> Task 6. Metrics -> Task 7. Engine (DAG, cycles, topo order, delays) -> Tasks 4, 5, 8. Doctor -> Task 9. Atomic writes -> Task 10. Auto config backups -> Task 11. Layout overrides (drag/groupings order + hard-dep validation) -> Task 12. Drift detection -> Task 13. Facade/precedence (user > detected) -> Task 14. Autostart format -> Task 3.
- Deferred to Plan B (correctly out of this plan's scope): `.plg` manifest + Min/Max version gating, emhttp UI, cron-driven metrics collection from live docker, reading real `unraid-autostart` from disk and applying it, snapshot persistence to flash. The core exposes pure functions Plan B will call.

**Placeholder scan:** No TBD/TODO; every code step contains complete, runnable code.

**Type consistency:** `Edge(from, to, type, source)` and the "from depends on to" convention are used identically in Tasks 2, 4, 5, 6, 8, 9, 12, 14. `Entry(name, wait)` consistent in Tasks 3, 8, 9, 14. `MetricsStore::median` consistent in Tasks 7, 8, 14. `AtomicWriter::write` consistent in Tasks 10, 11.

**Note for executor:** groupings (the UI-facing grouping of containers) are a Plan B presentation concern layered over the `Layout::apply` order; Plan A only needs the flat ordered list and hard-dep validation, which Task 12 provides.

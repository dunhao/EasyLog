# EasyLog

**A PHP-friendly, multi-tenant log platform that scales from a 2-container zero-dependency setup to a full ES + Kafka pipeline.**

[![CI](https://github.com/dunhao/EasyLog/actions/workflows/ci.yml/badge.svg)](https://github.com/dunhao/EasyLog/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.2-777bb4.svg)](https://www.php.net/)

[English](README.md) | [简体中文](README.zh-CN.md)

## Screenshots

| Search (Lucene syntax) | Dashboard |
|:---:|:---:|
| ![Search](docs/screenshots/search.png) | ![Dashboard](docs/screenshots/dashboard.png) |

| Alert rules (incl. σ-anomaly detection) | Ingest endpoints |
|:---:|:---:|
| ![Alerts](docs/screenshots/alerts.png) | ![Endpoints](docs/screenshots/endpoints.png) |

## Why EasyLog

- **Start tiny, scale on demand** — the default `lite` profile runs with just 2 containers (app + nginx) and zero external dependencies (SQLite storage, direct-write bus, file cache), ~300 MB RAM. Switch to `core`/`full` via a single config change when you need Elasticsearch, Kafka and multi-role deployment.
- **No code changes to collect logs** — a dependency-free single-file PHP agent tails your existing log directories (ThinkPHP `runtime/log/`, Laravel `storage/logs/`, JSON lines, or custom regex), merges multi-line stack traces, and ships with batching + offset checkpoints.
- **Query like you expect** — Lucene subset (`level:ERROR AND service:order-*`), SQL subset (`SELECT count(*) FROM logs WHERE ...`), time histograms, and term/metric aggregations work identically across all four storage backends (Elasticsearch / OpenObserve / Quickwit / SQLite).
- **Multi-tenant from day one** — workspace isolation, RBAC, three-level daily quotas, audit trail, alerting via webhook / email with baseline σ-anomaly detection.

## Quick start (lite tier, ~1 minute)

```bash
cd deploy/compose && ./start.sh
```

Open http://localhost:9516 (default account `admin@easylog.local` / `easylog@2026`), then send your first log:

```bash
curl -X POST http://localhost:9515/api/v1/ingest \
  -H 'X-EasyLog-App: app_demo_001' -H 'Authorization: Bearer sec_demo_001' \
  -H 'Content-Type: application/json' \
  -d '{"msg":"hello easylog","level":"ERROR","service":"order-api"}'
```

## Deployment tiers

| Tier | Command | Containers | Dependencies | For |
|---|---|---|---|---|
| **lite** (default) | `./start.sh` | 2 | **none** (SQLite + direct-write) | personal / dev / small teams |
| **core** | `./start.sh core` | 9 | host MySQL + Redis/ES/Kafka via compose | team / single-server production |
| **full** | `./start.sh full` | 11 | core + RabbitMQ + worker | external MQ ingest + scheduled alerts |

Tiers are mutually exclusive (`9515` conflict); switching is a config choice, not a data migration.

## Ingestion

All tiers expose the same HTTP ingest API (`lite` :9515, `core`/`full` dedicated gateway :9514):

1. **File agent** (no code changes) — drop `sdk/agent/easylog-agent` on the target server and point it at your log directory:

   ```bash
   php easylog-agent test  -c easylog-agent.json   # dry-run: see parsed entries
   php easylog-agent start -c easylog-agent.json   # daemon (or --once for cron)
   ```

   Parsers: `thinkphp` / `laravel` / `json` / `regex` / `plain`, with multi-line stack merging, rotation & truncation handling, and checkpoint-based resume. See [sdk/agent/README.md](sdk/agent/README.md).

2. **SDKs** (in-code, realtime) — PHP (Monolog handler), Go (slog handler), Java (Logback appender) under `sdk/`.

3. **External MQ** (full tier) — consume from Kafka / RabbitMQ / Redis Streams / ActiveMQ.

Credentials (AppID/AppSecret) are created in the console under *Workspace → Endpoints*.

## Measured performance (lite tier)

Reproducible via `php scripts/benchmark-ingest.php` — run it yourself:

| Metric | Result | Environment |
|---|---|---|
| Ingest throughput | **~1,200 logs/s** (4 concurrent × 200/batch, 0 failures) | Apple Silicon laptop, single container |
| Filtered search | **~30 ms** (term filter, 3,200 hits, page of 200) | SQLite WAL |
| Container memory | **~300 MB** | 2 Octane workers |

The `core` tier scales horizontally — ingestion, query and writer run as separate roles on Elasticsearch-backed storage.

## Docs

- [Architecture](docs/01-architecture.md) · [Detailed design](docs/02-detailed-design.md) · [Deployment guide](docs/05-deployment-guide.md) · [Usage](docs/04-usage-guide.md)
- SDKs: [agent](sdk/agent/README.md) · [php](sdk/php) · [go](sdk/go) · [java](sdk/java)

## Contributing & Security

PRs welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report vulnerabilities privately per [SECURITY.md](SECURITY.md).

## License

Apache-2.0 — see [LICENSE](LICENSE).

# Contributing to WP AI Mind

## Prerequisites

- Docker Desktop (running)
- Node.js 20+
- PHP 8.2+
- Composer 2+

## First-time Setup

```bash
composer install
npm install
npx playwright install chromium
```

> `npm install` also installs the pre-commit and pre-push git hooks automatically via the `prepare` script.

## Local WordPress Environment (wp-env)

Start a local WordPress instance with the plugin installed:

```bash
npm run env:start
# WordPress: http://localhost:8888
# Admin:     http://localhost:8888/wp-admin  (admin / password)
```

Stop it when done:

```bash
npm run env:stop
```

> To use your existing blog Docker environment instead, set `WP_BASE_URL=http://localhost:8080` when running Playwright tests.

## Running Checks Locally

### PHP linting (PHPCS)
```bash
composer run phpcs
# Auto-fix safe issues:
./vendor/bin/phpcbf --standard=phpcs.xml.dist
```

### PHP static analysis (PHPStan)
```bash
composer run phpstan
```

### PHP compatibility check
```bash
./vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.1 --extensions=php --ignore=*/vendor/*,*/tests/* .
```

### JavaScript / CSS linting
```bash
npm run lint:js
npm run lint:css
```

### Unit tests (PHPUnit)
```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
```

### E2E tests (Playwright)
```bash
npm run env:start          # start wp-env if not running
npm run test:e2e           # run all specs
npm run test:e2e:ui        # interactive UI mode
npm run test:e2e:debug     # step-through debug mode
npm run test:e2e:report    # open the last HTML report
```

## Git Hooks

Hooks are installed automatically by `npm install`:

| Hook | When | What it does |
|---|---|---|
| pre-commit | Every commit | Builds assets if `src/` changed; runs PHPCS on staged PHP files |
| pre-push | Every push | Full PHPCS + PHPStan pass |

## Branch & PR Rules

- Never commit directly to `main` — always use a feature branch
- Branch naming: `feat/`, `fix/`, `chore/`, `refactor/`, `test/`
- PR titles must follow [Conventional Commits](https://www.conventionalcommits.org/): `feat(scope): description`
- All PRs target `main` directly

## CI Checks on PRs

When you open a PR against `main`, two workflows run:

**`ci.yml`** (existing):
- PHPCS coding standards
- PHPUnit unit tests
- JS/CSS linting
- Conventional commit lint

**`pr-checks.yml`** (new):
- PHPStan static analysis
- PHP compatibility matrix (8.1, 8.2, 8.3)
- Security audit (Composer + npm)
- Semgrep — security scan (PHP, OWASP Top 10); SARIF uploaded to Security tab
- WP Plugin Check — WordPress.org's plugin auditor (general/security/performance/accessibility)
- Lighthouse — front-end performance budget against a wp-env site
- E2E Playwright tests (runs on the maintainer's Mac via Tailscale SSH)

## Required GitHub Secrets

For the Tailscale SSH E2E job to work, these must be set in the repo settings:

| Secret | Description |
|---|---|
| `TAILSCALE_AUTHKEY` | Tailscale ephemeral auth key |
| `MAC_SSH_HOST` | Tailscale hostname of the Mac runner |
| `MAC_SSH_USER` | SSH username on the Mac |
| `MAC_SSH_PRIVATE_KEY` | Private SSH key (public key in `~/.ssh/authorized_keys` on Mac) |

## Release Process

Releases are fully automated via semantic-release. Never bump version numbers manually.
See [RELEASING.md](RELEASING.md) for details.

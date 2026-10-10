# Peanut Suite project starting point

Preserve and maintain the repository’s WordPress product and shared Firebase surfaces; clarify their current product ownership before expanding scope.

## Direction

Documented intent: Preserve and maintain the repository’s WordPress product and shared Firebase surfaces; clarify their current product ownership before expanding scope.

Owner confirmation needed: ultimate scope, launch or completion acceptance criteria, and the next priority. This brief does not mark any feature or project complete.

## Source baseline

- Inspected: 2026-10-09
- Local branch: `feat/signed-update-gate`
- Local HEAD: `66d2f095b1fa5a82f14d165103e484f4fc5e3de7`
- Lifecycle registry: `active`; classification: `distributed-plugin` (registry reviewed 2026-08-29; current lifecycle not independently confirmed).
- Working tree at inspection: contains local changes.

## What it is built with

- package.json: Node tooling
- frontend/package.json: react ^18.3.1; tailwindcss ^4.0.0; typescript ^5.7.2; vite ^6.0.3; vitest ^2.0.0
- functions/package.json: firebase-admin ^12.0.0; typescript ^5.3.0; engines {"node": "20"}
- composer.json: php >=8.1
- peanut-suite.php: WordPress plugin Peanut Suite; WordPress 6.0; PHP 8.1

## Build and verification entry points

These are source-declared entry points, not commands executed by this audit. Install dependencies and configure a local environment according to the linked setup documentation before running them. Release/hosting actions remain subject to the project’s deployment rules.

- package.json: `npm run dev`
- package.json: `npm run build`
- frontend/package.json: `npm run dev`
- frontend/package.json: `npm run build`
- frontend/package.json: `npm test`
- frontend/package.json: `npm run typecheck`
- functions/package.json: `npm start`
- functions/package.json: `npm run build`
- functions/package.json: `npm run lint`
- composer.json: `composer test`

## Structure and relationships

- `functions/` is present in this checkout.
- `tests/` is present in this checkout.
- Cross-project integration details belong in the linked architecture and API documents; folder similarity alone does not establish a dependency.

## Gaps to resolve

- README foregrounds a Firebase comedy backend while ecosystem overview calls this a marketing plugin. Product boundaries need reconciliation.
- Working tree has uncommitted changes; this baseline includes local declarations beyond the recorded HEAD.
- Owner acceptance criteria and current priority order are not yet recorded in this brief.

## Source documents

- [README.md](README.md)
- [ROADMAP.md](ROADMAP.md)
- [docs/](docs/)

## Keeping this current

Update this starting point when product direction, architecture, dependencies, or build/release entry points change. Recheck source facts against the current checkout. Date stamps describe inspection, not live production verification.

<!-- dino-source-audit {"date":"2026-10-09","head":"66d2f095b1fa5a82f14d165103e484f4fc5e3de7","sources":{"README.md":"e4c4ff7ac009af8e0e29c7394e6d5d065cd127e343cf13bb3d612f5a254cfb05","package.json":"f2601331c96274ec262fb0026d595409f3d14738c2cfba3a1cf7954bdc7f147f","composer.json":"9f7090bcff662e428cdc40d3d34ae1906400686f559469d0b698d1291e15dd29","frontend/package.json":"e714e897c2e1af95a9674a2720b340888b5e363aca3488035ccd19bd44e2e37d","functions/package.json":"20a27132102ec5b9eeec3df7d58b159f9fb75000db4266291977957a929880d0","peanut-suite.php":"95515e173b671a72620ddc21689fe6a4ab62e52198baf5dda3850761b0757435","ROADMAP.md":"8f8c4d6c63bbcc91f0be6f663e3bcbadb3c1b13bcc9dd6bfedd1d568d84939ba"}} -->

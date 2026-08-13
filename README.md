<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

## HTTPS Development Setup

This project now terminates HTTPS through Caddy in Docker and supports both of these entry points:

- https://localhost

### Start the stack

```bash
docker compose up -d
npm run dev
```

### How it works

- Caddy listens on ports 80 and 443 and proxies Laravel plus the Vite dev server.
- Laravel trusts forwarded proxy headers, so request URLs and secure cookies behave correctly behind HTTPS.
- Vite is exposed through the app origin under `/vite-dev`, and HMR uses `/vite-hmr`, so the same dev server works under either hostname.

### Certificate notes

- `localhost` uses Caddy's internal CA. If your browser warns on the certificate, export the local root CA from the proxy container and trust it on your machine.

Example command to export the local Caddy root certificate:

```bash
docker compose exec caddy cat /data/caddy/pki/authorities/local/root.crt > caddy-local-root.crt
```



## Monopoly Game — Overview

This repository hosts a multiplayer, turn-based implementation of the classic Monopoly board game. The app models players, tokens, properties, money, Chance and Community Chest cards, auctions, building houses/hotels, mortgages, and the standard rules that govern movement, rent, and win conditions.

### Game dynamics

- Players join a game (authenticated users or invited guests) and take turns rolling dice to move their token around the board.
- Landing on an unowned property gives the player the option to purchase it; otherwise the property may be auctioned to other players.
- Owned properties charge rent to visiting players and can be improved with houses/hotels to increase rent.
- Chance and Community Chest cards are drawn when a player lands on the corresponding squares and trigger a variety of events (pay/receive money, move, jail, get-out-of-jail cards, etc.).
- Players can mortgage and unmortgage properties, sell buildings, and trade with each other as part of strategic play.
- Jail mechanics, bankruptcy, and win conditions follow standard Monopoly rules — a player is eliminated when unable to satisfy debts and the last remaining solvent player wins.

### Rules framework used by this project

This application is built around a Monopoly-style rule set inspired by the standard commercial board-game framework, but implemented as a digital game engine rather than a literal ruleset clone from one specific edition. The important conventions the project follows are:

- Turn order is sequential and deterministic: each player takes one turn in join-order sequence, with the active turn advancing after a full action cycle.
- Movement is based on dice rolls and board squares, with other game events chaining off the square landed on (rent, property purchase, card draw, movement penalties or bonuses, and jail).
- Property ownership is group-aware: color sets matter for building rights, and houses/hotels are placed only when the player owns a complete set and the bank has stock available.
- Rent is derived from board-state and property status, including ownership, improvements, mortgage state, and special square effects.
- Bankruptcy is a debt-resolution flow: if a player cannot pay a required amount after available assets and mortgage options are considered, they can be declared bankrupt and eliminated from the game.
- Mortgage and building-sale mechanics are treated as liquidity tools: players may convert assets into cash to remain solvent, while partially rebuilt color groups can be gated to prevent unstable or invalid builds.
- Game completion is win-based rather than score-based: once all rival players are bankrupt or otherwise eliminated, the last solvent player remains the winner.
- Cards and effects are state-driven: Chance and Community Chest outcomes are resolved by the game engine using the same move, payment, and status changes that affect the board state in real time.

These principles are the core framework the app uses when resolving turns, updating board state, and broadcasting real-time gameplay changes to all players.

### Multiplayer behaviour

- Real-time game events (token movement, turn advancement, purchases, and card draws) are published to interested clients so all players see a consistent board state.
- The board rendering supports multiple tokens per square using a "stationed" layout to avoid overlap and a token-motion animation to show moves clearly.

## Development techniques and architecture

- Backend: Laravel framework for APIs, business logic, and persistence. Controllers are thin; domain logic is implemented in services and repositories to keep responsibilities separate.
- Frontend: Inertia.js with Vue 3 components and Vite for fast development builds and HMR. UI styling uses Tailwind CSS for utility-first layout.
- Authentication: Laravel Sanctum provides SPA-friendly authentication for users; invitation tokens are used to gate guest access to specific games.
- Real-time & events: Laravel events and broadcasting are used to emit game state changes to clients (websockets or a broadcast driver supported in production).
- Data & caching: Redis is used for transient state and caching where appropriate (e.g. game state snapshots and rate-limiting hooks).
- Testing: PHPUnit for backend tests and Vitest for frontend component/unit tests. Services and repository logic are unit-tested in isolation; Mockery is used for reliable mocking of dependencies in PHP tests.
- Dev tooling: Docker Compose + Caddy provide HTTPS local development; Vite serves frontend assets during development. The repository includes scripts to run the full development stack quickly.
- CI: The project is structured to be CI-friendly — running `npm ci`, `npm run build`, and `phpunit` is sufficient to verify the build and tests in an automated workflow.

## Where to start locally

1. Start the stack:

```bash
docker compose up -d
npm run dev
```

2. Visit the app at `https://localhost` (or the hostname configured for your Caddy dev proxy).

3. Run tests:

```bash
npm ci
npm test
./vendor/bin/phpunit
```

If you need help with local certificates for `https://localhost`, see the certificate notes earlier in this README.

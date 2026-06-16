<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

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

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Monopoly Game — Overview

This repository hosts a multiplayer, turn-based implementation of the classic Monopoly board game. The app models players, tokens, properties, money, Chance and Community Chest cards, auctions, building houses/hotels, mortgages, and the standard rules that govern movement, rent, and win conditions.

### Game dynamics

- Players join a game (authenticated users or invited guests) and take turns rolling dice to move their token around the board.
- Landing on an unowned property gives the player the option to purchase it; otherwise the property may be auctioned to other players.
- Owned properties charge rent to visiting players and can be improved with houses/hotels to increase rent.
- Chance and Community Chest cards are drawn when a player lands on the corresponding squares and trigger a variety of events (pay/receive money, move, jail, get-out-of-jail cards, etc.).
- Players can mortgage and unmortgage properties, sell buildings, and trade with each other as part of strategic play.
- Jail mechanics, bankruptcy, and win conditions follow standard Monopoly rules — a player is eliminated when unable to satisfy debts and the last remaining solvent player wins.

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

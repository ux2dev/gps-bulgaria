# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-10-06

### Added
- Objects: list, get, statuses, status, routes.
- Object types: list.
- Zones: list, get, search, create, with lat-first geometry factories.
- Alerts (experimental): list, forObject.
- An exception per HTTP status, and opt-in retry for GET on 503 / transport errors.
- Laravel integration: tenants, `forKey()` runtime keys, facade.

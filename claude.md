# Editorial VCS

## What This Is

Editorial VCS is a self-hosted editorial version control platform for creative projects. PHP/MariaDB backend, CodeMirror editor, diff2html diffs, status flags, deploy-to-production pipeline, and a D3.js relationship graph.

This repo is being extracted from a production system that was purpose-built for a specific transmedia project. The extraction effort is about making it generic, configurable, and installable by someone who isn't the original author.

## Tech Stack

- PHP 8.x
- MariaDB 10.x (MySQL 8.x compatible)
- CodeMirror (inline editing)
- diff2html (side-by-side version diffs)
- D3.js (relationship graph — optional feature)
- Apache with mod_rewrite
- Docker for packaging

## Extraction Principles

This codebase was built for a single project with hardcoded silos and assumptions. The goal is:

1. **Strip project-specific content** — any references to the original project's names, silo labels, characters, terminology, or domain names must be removed or replaced with configurable values.
2. **Make projects data-driven** — project/silo definitions should come from the database and a config file, not from hardcoded PHP. A new user creates their first project through a setup wizard or admin UI.
3. **Credentials out of the tree** — no passwords, API keys, server paths, or .htaccess secrets in the repo. Use `config.php` (gitignored) with a `config.example.php` template, and support environment variables for Docker.
4. **Keep it simple** — this is one-person PHP software and that's fine. Don't introduce frameworks, ORMs, or build systems. The value is in the editorial workflow, not the architecture.
5. **Relationship graph is optional** — the D3.js graph ships as a feature but must be toggleable via config. Not every project needs entity mapping.

## Target File Structure

```
editorial-vcs/
├── docker-compose.yml
├── Dockerfile
├── .env.example
├── schema.sql
├── config.example.php
├── README.md
├── LICENSE
├── src/
│   ├── index.php
│   ├── editor.php
│   ├── diff.php
│   ├── deploy.php
│   ├── graph.php
│   ├── setup.php              # First-run setup wizard
│   ├── admin/
│   │   ├── projects.php
│   │   └── users.php
│   ├── api/
│   │   └── documents.php
│   ├── includes/
│   │   ├── db.php
│   │   ├── auth.php
│   │   ├── versioning.php
│   │   └── deploy-target.php
│   └── assets/
│       ├── css/
│       ├── js/
│       └── vendor/
├── docs/
└── tests/
```

## What "Done" Looks Like

- A clean `docker compose up` brings up a working instance
- First-run setup wizard creates an admin user and first project
- No references to the original project anywhere in the code
- README.md accurately describes the current state
- `schema.sql` creates all necessary tables from scratch
- Config is documented in `config.example.php` with comments

## Code Style

- Plain PHP, no framework. Procedural is fine where it's clear.
- SQL via PDO with prepared statements.
- HTML can be inline in PHP files — this isn't a SPA.
- Comments where intent isn't obvious; don't over-document the obvious.
- Functions over classes unless the abstraction genuinely helps.

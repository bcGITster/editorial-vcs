# Editorial VCS

A self-hosted editorial version control system for creative projects. Track revisions, manage status workflows, and optionally maintain a lore bible with relationship visualization.

## Features

- **Version Control**: Full-content revisions with commit messages, diff comparison, and revision history
- **Project Silos**: Organize files into separate projects with independent workflows
- **Status Workflow**: Draft → Review → Final pipeline for editorial processes
- **File Promotion**: Copy files between projects while preserving history
- **Production Import**: Pull existing files from a deploy path into version control
- **CodeMirror Editor**: Syntax-highlighted in-browser editing
- **Lore Bible** (optional): Entity database with cross-referencing across documents
- **Relationship Graph** (optional): D3.js visualization of entity connections

## Quick Start (Docker)

```bash
# Clone and configure
git clone https://github.com/yourusername/editorial-vcs.git
cd editorial-vcs
cp .env.example .env
# Edit .env with your settings

# Start
docker compose up -d

# Access at http://localhost:8080
# First-run wizard creates admin account and first project
```

## Manual Installation

### Requirements

- PHP 8.0+
- MariaDB 10.x / MySQL 8.x
- Apache with mod_rewrite (or nginx with equivalent config)

### Steps

1. Copy `src/` contents to your web root
2. Copy `config.example.php` to `config.php` and edit settings
3. Create database and run `schema.sql`
4. Access the application — setup wizard runs automatically

```bash
# Create database
mysql -u root -p <<EOF
CREATE DATABASE editorial_vcs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'editorial_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON editorial_vcs.* TO 'editorial_user'@'localhost';
FLUSH PRIVILEGES;
EOF

# Import schema
mysql -u editorial_user -p editorial_vcs < schema.sql
```

## Configuration

Copy `config.example.php` to `config.php`:

```php
return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'editorial_vcs',
        'user'     => 'editorial_user',
        'password' => 'your_password',
    ],
    'app' => [
        'title'    => 'My Editorial VCS',
        'timezone' => 'America/New_York',
    ],
    'features' => [
        'lore_bible'         => true,  // Entity database
        'relationship_graph' => true,  // D3.js graph visualization
    ],
];
```

All settings can also be set via environment variables (see `.env.example`).

## Usage

### Projects

Projects are isolated silos for organizing files. Each project can have:
- An optional **deploy path** for importing existing files from production
- Independent file status tracking
- Separate revision histories

### Files & Revisions

- Every save creates a new revision with full content (not diffs)
- Compare any two revisions with side-by-side diff view
- Download individual files or entire projects as zip

### Status Workflow

Files progress through: **Draft** → **Review** → **Final**

Status is independent of revision history — you can mark a file as final, then continue editing (new revisions stay final until you change status).

### Lore Bible (Optional)

For creative projects, the Lore Bible tracks entities (characters, locations, organizations, etc.):
- Categorized entries with faction/group tags
- Aliases for cross-reference matching
- Automatic detection of entity mentions across project files
- Export to Markdown

### Relationship Graph (Optional)

D3.js force-directed graph showing connections between entities based on document co-occurrence.

## Tech Stack

- PHP 8.x (no framework)
- MariaDB/MySQL
- CodeMirror (in-browser editing)
- diff2html (revision comparison)
- D3.js (relationship graph)

## File Structure

```
editorial-vcs/
├── docker-compose.yml
├── Dockerfile
├── .env.example
├── schema.sql
├── config.example.php
├── README.md
└── src/
    ├── index.php          # Main application
    ├── setup.php          # First-run wizard
    ├── graph.php          # Relationship visualization
    └── includes/
        ├── config.php     # Configuration loader
        ├── db.php         # Database connection
        ├── auth.php       # Authentication
        ├── helpers.php    # Utility functions
        └── versioning.php # File/revision operations
```

## License

MIT

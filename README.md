# API Doc Import Plugin

Import OpenAPI/Swagger specifications into Grav pages for the Helios documentation theme.

## Features

- Import from local files (JSON/YAML) or URLs
- Supports OpenAPI 3.x and Swagger 2.x specifications
- Generates structured documentation pages:
  - Chapter pages for API tag groups
  - API endpoint pages with parameters, examples, and response codes
- Auto-generates request/response examples from schemas
- Preserves manual content additions when updating
- Incremental updates (only update changed endpoints)

## Requirements

- Grav 1.7+
- PHP 8.1+
- [Helios Documentation Theme](https://getgrav.org/premium/helios) 1.0+

## Installation

Install via GPM:

```bash
bin/gpm install api-doc-import
```

Or manually download and extract to `user/plugins/api-doc-import`.

## Usage

### CLI Import

The primary way to import API documentation is via the CLI:

```bash
# Import from local file
bin/plugin api-doc-import import openapi.yaml v3/api-reference

# Import from URL
bin/plugin api-doc-import import https://api.example.com/openapi.json v3/api-reference

# Update existing pages
bin/plugin api-doc-import import openapi.yaml v3/api-reference --update

# Update without preserving manual content
bin/plugin api-doc-import import openapi.yaml v3/api-reference --update --no-preserve

# Flat structure (no tag folders)
bin/plugin api-doc-import import openapi.yaml v3/api-reference --flat
```

### Output Structure

Given an OpenAPI spec with tags "Users" and "Posts", the plugin generates:

```
pages/
└── v3/
    └── api-reference/
        ├── 01.users/
        │   ├── chapter.md
        │   ├── 01.create-user/
        │   │   └── api-endpoint.md
        │   ├── 02.get-user/
        │   │   └── api-endpoint.md
        │   └── 03.update-user/
        │       └── api-endpoint.md
        └── 02.posts/
            ├── chapter.md
            └── ...
```

### Generated Page Structure

Each `api-endpoint.md` contains frontmatter with:

```yaml
---
title: Create User
template: api-endpoint
taxonomy:
    category: docs
api:
    method: POST
    path: /users
    description: Creates a new user account
    parameters:
        - name: email
          type: string
          required: true
          description: User's email address
        - name: name
          type: string
          required: true
          description: User's display name
    request_example: |
        {
          "email": "john@example.com",
          "name": "John Doe"
        }
    response_example: |
        {
          "id": "usr_abc123",
          "email": "john@example.com",
          "name": "John Doe"
        }
    response_codes:
        - code: 201
          description: User created successfully
        - code: 400
          description: Invalid request body
---
```

## Configuration

Configure defaults in `user/config/plugins/api-doc-import.yaml`:

```yaml
enabled: true

# Default output path
output_path: 'api-reference'

# Organize endpoints by tags
organize_by_tags: true

# Starting number for folder prefixes
folder_prefix_start: 1

# Update existing pages when re-importing
update_existing: false

# Preserve manual content when updating
preserve_content: true
```

## Workflow

1. **Initial Import**: Run the import command to generate pages from your OpenAPI spec
2. **Customize**: Edit generated pages to add additional notes, examples, or documentation
3. **Update**: When your API changes, re-run with `--update` to sync changes while preserving your customizations

## License

MIT License - see [LICENSE](LICENSE) for details.

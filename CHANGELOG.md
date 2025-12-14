# v1.0.0
## 12/14/2024

1. [](#new)
    * Initial release of API Doc Import plugin
    * CLI command for importing OpenAPI/Swagger specifications
    * Support for OpenAPI 3.x and Swagger 2.x formats
    * Import from local files (JSON/YAML) or remote URLs
    * Automatic page generation with `api-endpoint` template
    * Tag-based organization into chapter folders
    * Request/response example generation from schemas
    * Parameter extraction (path, query, header, body)
    * Response code documentation
    * Smart request examples that exclude server-generated fields (id, timestamps, etc.)
    * Content preservation when updating existing pages
    * Configurable folder numbering prefixes
    * Support for multiple content types (JSON, form-urlencoded, multipart)
    * `$ref` resolution for schema references

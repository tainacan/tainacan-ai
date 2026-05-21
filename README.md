# Tainacan AI
[![Project Status: WIP – Initial development is in progress, but there has not yet been a stable, usable release suitable for the public.](https://www.repostatus.org/badges/latest/wip.svg)](https://www.repostatus.org/#wip)

WordPress plugin that integrates [Tainacan](https://wordpress.org/plugins/tainacan/) with **WordPress AI** (via the **Connectors** screen) to extract metadata from item documents and images.

AI routing and credentials are managed by WordPress; this plugin focuses on prompts, analysis, mapping, and Tainacan-specific UX.

## Features

- **WordPress AI & Connectors**: Uses the site’s configured AI connectors
- **Compatible with Tainacan 1.0+**: Uses the Pages and Admin Form Hooks APIs
- **Smart cache**: Caching with manual clear from settings
- **Custom prompts**: Default prompts and per-collection overrides
- **Metadata mapping**: Map AI-extracted fields to Tainacan metadata (supports “Fill fields” workflows)
- **EXIF extraction**: Optional EXIF extraction from images (when enabled and supported by the server)
- **PDF support**: Text extraction and optional visual analysis for PDFs (depends on server extensions and the configured connector)

### Observability

When the WordPress **AI** plugin (1.0.0+) is active and the **AI Request Logging** feature is enabled (under **Settings → AI**, with **AI features** turned on globally), Tainacan AI adds a `tainacan_ai` block to each log entry under **Tools → AI Request Logs** (attachment, item, collection, document type, extraction method). Core also records request **source** (plugin slug/file) with improved attribution that typically includes `tainacan-ai` rather than only the connector provider. Logs are retained indefinitely unless the site filters `wpai_request_log_retention_days`. This plugin does not ship a separate usage dashboard or API keys.

With `WP_DEBUG` enabled, non-HTTP failures (PDF extraction, conversion, etc.) and one-line analysis summaries are also written to the PHP error log (alongside Core request logs when that feature is on).

## Installation

1. Upload the `tainacan-ai` folder to `/wp-content/plugins/`
2. Run `composer install` in the plugin folder (for PDF text extraction via the bundled parser)
3. Activate the plugin in WordPress
4. Configure AI **connectors** under **Settings → Connectors** (WordPress 7.0+)
5. Open **Tainacan → AI Tools** for prompts, features, cache, and mapping (not for API keys)

## Development

### Prerequisites

- Node.js 20.0+ (Active LTS recommended)
- npm 10.0+
- Composer (for PHP dependencies)

### Setup

```bash
# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install
```

### Build Commands

```bash
# Development mode (watch files, rebuild on changes)
npm start

# Production build (compiled assets)
npm run build

# Lint JavaScript code
npm run lint:js

# Auto-fix JavaScript linting issues
npm run lint:js:fix

# Format JavaScript code
npm run format:js
# Or use the general format command (includes JS and CSS)
npm run format

# Lint CSS/Styles
npm run lint:css

# Update WordPress package dependencies
npm run packages-update

# Generate plugin ZIP for WordPress.org
npm run plugin-zip
```

### Build Output

Built assets are output to `build/` (for example `build/admin.js`, `build/item-form.js`, and matching CSS bundles). The enqueue logic uses these when present.

### WordPress.org Submission

When preparing a release for WordPress.org:

1. Run `npm run build` to generate production assets
2. Run `npm run plugin-zip` to create a distribution-ready ZIP
3. The `.distignore` file controls which files are included

**Important:** The ZIP typically includes:

- `includes/` — PHP classes and admin templates
- `lib/` — embedded third-party libraries (e.g. PDF parsing)
- `languages/` — translations
- `build/` — compiled JS/CSS
- `tainacan-ai.php` — main plugin file
- `readme.txt` — WordPress.org readme

## Requirements

- **WordPress 7.0+** (WordPress AI / Connectors)
- **PHP 8.0+**
- **Tainacan 1.0+**
- At least one **AI connector** configured in **Settings → Connectors** (exact models and providers depend on that configuration)

## Usage

1. Ensure AI connectors are set up in **Settings → Connectors**
2. Optionally adjust default prompts under **Tainacan → AI Tools** and per-collection prompts on each collection's edition form
3. Edit a Tainacan item with an attached document
4. In the **AI Metadata Extractor** section, click **Analyze Document**
5. Review results and fill metadata (manually or using the provided actions)

## File types

Tainacan AI only starts analysis for recognized MIME types: JPEG, PNG, GIF, WebP, PDF, plain text, HTML, and Word (`.doc` / `.docx`). Anything else is rejected before an AI request is sent.

Listing a format here does not guarantee a successful result:

| Kind | What must work |
|------|----------------|
| Images | Connector model with image input (see **Image analysis** on **Tainacan → AI Tools**) |
| PDF (text) | Bundled PDF text parser |
| PDF (scanned / visual) | Imagick or Ghostscript **and** a vision-capable connector |
| EXIF (optional) | PHP `exif` extension |

Use **Analyze Document** on a real item to confirm your connector and server configuration.

## REST API

```
POST /wp-json/tainacan-ai/v1/analyze/{attachment_id}
GET  /wp-json/tainacan-ai/v1/status
DELETE /wp-json/tainacan-ai/v1/cache/{attachment_id}
```

## Structure

```
tainacan-ai/
├── tainacan-ai.php         # Main plugin file
├── includes/
│   ├── AdminPage.php       # Admin page (Tainacan Pages API)
│   ├── ItemFormHook.php    # Form integration (Admin Form Hooks)
│   ├── DocumentAnalyzer.php
│   ├── API.php             # REST API endpoints
│   ├── CollectionPrompts.php   # Post meta + metadata for mapping UI
│   ├── CollectionFormHook.php  # Per-collection prompts on edition form
│   ├── ExifExtractor.php
│   ├── CoreAI.php              # WordPress AI client integration
│   ├── CoreAIRequestLogging.php
│   └── admin/
│       └── admin-page.php  # Settings page template
├── lib/                    # Embedded third-party libraries
│   └── PdfParser/
├── src/                    # Source assets
│   ├── css/
│   └── js/
├── build/                  # Compiled assets
└── languages/
```

## License

GPL v2 or later

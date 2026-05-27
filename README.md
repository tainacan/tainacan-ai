# Tainacan AI
[![Project Status: WIP – Initial development is in progress, but there has not yet been a stable, usable release suitable for the public.](https://www.repostatus.org/badges/latest/wip.svg)](https://www.repostatus.org/#wip)

WordPress plugin that integrates [Tainacan](https://wordpress.org/plugins/tainacan/) with **WordPress AI** (via the **Connectors** screen) to extract metadata from item documents and images.

AI routing and credentials are managed by WordPress; this plugin focuses on prompts, per-metadata extraction, analysis, and Tainacan-specific UX.

## Features

- **WordPress AI & Connectors**: Uses the site’s configured AI connectors
- **Compatible with Tainacan 1.0+**: Uses the Pages and Admin Form Hooks APIs
- **Smart cache**: Caching with manual clear from settings
- **Custom prompts**: One default analysis prompt plus per-collection overrides
- **Prompt templates**: Suggested prompt templates in AI Tools that can be copied into the default prompt
- **Per-metadata extraction opt-out**: All collection metadata is included by default; check **Exclude from AI extraction** on the metadatum form to omit a field
- **Evidence per field**: Every analysis appends standardized instructions so each metadata key returns `{ "value", "evidence" }`
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
5. Open **Tainacan → AI Tools** for prompts, features, and cache (not for API keys)

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
2. Optionally adjust the default prompt (and templates) under **Tainacan → AI Tools** and per-collection prompts on each collection's edition form
3. Edit a Tainacan item with an attached document
4. In the **AI Metadata Extractor** section, click **Analyze Document**
5. Review results and fill metadata (manually or using the provided actions)

### How the final prompt is built

At analysis time, the plugin composes the final prompt in a fixed order (single request, no multi-step orchestration):

1. **User preamble**  
   From collection prompt (`tainacan_ai_prompt_text`) or the site default prompt.
2. **Task**  
   A short instruction with **analysis mode** (`image`, `text`, `pdf_text`, `pdf_visual`).
3. **Global rules**  
   Non-fabrication boundaries and JSON-only output requirement.
4. **Field blocks**  
   Built from extraction-enabled metadata in the collection, using metadata **slug** as JSON key and including type, label, mode (`strict`/`exploratory`), plus optional constraints (for example `required`, `max_items`, `min/max/step`, `max_length`, `allowed_values`, taxonomy and relationship hints). For taxonomy fields, `allowed_values` can include a ranked list of existing terms.
5. **Field format contract**  
   Compact `{ "value", "evidence" }` format rules, including multivalue parallel arrays.
6. **Evidence rules module**  
   Injected using the same **analysis mode** (`image`, `text`, `pdf_text`, `pdf_visual`) based on file type/content.
7. **Output keys closure**  
   Explicit list of expected slugs.

Implementation reference:
- `AnalysisPromptComposer::get_context()`
- `DocumentAnalyzer::resolve_analysis_prompt()`

Available prompt customization filters:
- `tainacan_ai_analysis_prompt_sections` (section array before join)
- `tainacan_ai_analysis_prompt` (final composed prompt)
- `tainacan_ai_evidence_instructions` (evidence rules block)
- `tainacan_ai_extraction_field` (per-metadatum field block data before serialization)
- `tainacan_ai_taxonomy_allowed_values_limit` (max ranked taxonomy terms sent in `allowed_values`)
- `tainacan_ai_taxonomy_allowed_values` (final ranked taxonomy term list before prompt serialization)

### AI response shape

Each extracted field should be returned as an object:

```json
{
  "titulo": {
    "value": "Example title",
    "evidence": "Visible on the cover, top center"
  }
}
```

For taxonomy fields with `allow_new_terms: true`, responses may also include `pending_new_terms` when the AI found evidence for a term label that does not match current `allowed_values`:

```json
{
  "assunto": {
    "value": [],
    "evidence": ["Caption under photo"],
    "pending_new_terms": [
      { "label": "Photomontage", "evidence": "Caption under photo" }
    ]
  }
}
```

In that case, the item form shows these suggestions in a dedicated block and offers explicit term creation. Creation is user-driven (no automatic insertion during analysis).

Evidence instructions are appended automatically at analysis time (image vs. text vs. scanned PDF). Prompt templates list example field keys only; they do not define per-field evidence text.

Multivalued fields use **parallel arrays** inside one object (`value` and `evidence` with the same length), not an array of per-item `{ value, evidence }` objects.

The plugin appends field blocks for metadata marked for extraction (slug as JSON key). These blocks include optional guidance from description/placeholder and optional constraints derived from metadata settings, such as `required`, multivalue limits (`max_items`), type limits (`min/max/step`, `max_length`, `mask`), `allowed_values` for selectboxes, and taxonomy/relationship structure hints. It does not replace your introduction (see [issue #7](https://github.com/tainacan/tainacan-ai/issues/7)). Configure extraction on each metadata edition form under **Tainacan AI → Exclude from AI extraction** (unchecked by default).

You can customize this per metadatum via `tainacan_ai_extraction_field`. The plugin itself now uses this same hook to inject built-in type hints, so custom metadata types or site-specific rules can reuse one API. Catalog-style hints (for example `taxonomy_allowed_values` on the item form) use rows `{ "value", "label" }`: `value` is the machine-readable payload for REST (term ID today), and `label` is the string shown in prompts and in the UI.

Taxonomy insertion/creation is not automatic during analysis: taxonomy values remain suggestion-oriented in extraction output. New terms can be created explicitly from the item form when `allow_new_terms` is enabled.

Filter: `tainacan_ai_evidence_instructions` to customize the appended evidence block.

## File types

Tainacan AI only starts analysis for recognized MIME types: JPEG, PNG, GIF, WebP, PDF, plain text, and HTML. Anything else is rejected before an AI request is sent.

For item documents configured as **URL** in Tainacan, analysis currently supports **HTTPS** links that resolve to PDF, plain text, or HTML content.

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
│   ├── CollectionPrompts.php   # Per-collection prompt post meta
│   ├── CollectionFormHook.php  # Per-collection prompts on edition form
│   ├── PromptTemplates.php     # Suggested prompt templates for admin UI
│   ├── EvidenceInstructions.php # Runtime file-type evidence guidance (by analysis mode)
│   ├── ExtractionMetadata.php   # Per-metadatum flag + dynamic field list for prompts/fill
│   ├── MetadatumFormHook.php    # Toggle extraction on metadata edition form
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

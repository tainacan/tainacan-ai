=== Tainacan AI ===
Contributors: Sigismundo, tainacan, wetah, daltonmartins, vnmedeiros
Tags: tainacan, ai, cataloging, museums, image-analysis
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated metadata extraction for Tainacan using WordPress AI and Connectors. Images, PDFs, and custom prompts.

== Description ==

Tainacan AI extends the [Tainacan](https://wordpress.org/plugins/tainacan/) plugin with AI-assisted metadata extraction from images and documents. It is intended for museums, archives, libraries, and digital repositories that want to speed up cataloging while keeping control of prompts and which metadata fields are extracted.

**AI access** is provided by **WordPress AI** through **Settings → Connectors** (WordPress 7.0+). This plugin does not store API keys for OpenAI, Gemini, or other vendors in its own settings screen; which model or service runs depends on your site's connector configuration.

= Key Features =

* **WordPress AI & Connectors**: Uses the site's configured AI connectors
* **Image analysis**: Extract structured metadata from images when your connector supports it
* **Document analysis**: Extract metadata from PDFs, TXT, and HTML using collection field slugs
* **Custom prompts per collection**: A single prompt per collection to guide extraction for any supported file type
* **Per-metadata extraction**: All metadata is included by default; opt out per field with **Exclude from AI extraction** on the metadatum form
* **Evidence per field**: Analysis requests `{ "value", "evidence" }` for each key
* **EXIF data extraction**: Optional technical metadata from image files (when the server supports it)
* **PDF support**: Text extraction via bundled parser; optional visual analysis depends on Imagick/Ghostscript and the connector
* **Smart caching**: Reduce repeat analysis with caching and a manual clear action
* **WP Consent API**: Optional consent gate for AI-powered actions (when enabled in settings)

= File types =

The plugin will attempt analysis only for attachments whose MIME type it recognizes (JPEG, PNG, GIF, WebP, PDF, plain text, and HTML). Other types are rejected before any AI request runs.

For item documents configured as URL in Tainacan, analysis currently supports HTTPS links that resolve to PDF, plain text, or HTML.

Whether a supported file is fully processed depends on your setup—not on this list alone:

* **Images** need a connector model that accepts image input (check status under **Tainacan → AI Tools**).
* **PDFs** use text extraction when possible; visual analysis of scanned PDFs additionally requires Imagick or Ghostscript and a vision-capable connector.
* **EXIF** metadata is optional and requires the PHP EXIF extension.

= How It Works =

1. Configure **AI connectors** under **Settings → Connectors** in WordPress
2. Optionally set default prompts and features under **Tainacan → Others → AI Tools**
3. Enable or disable extraction per metadata on each metadatum edition form (all fields are on by default)
4. Upload an image or document to a Tainacan item
5. Click **Analyze Document** in the item edit form
6. Review extracted fields and fill Tainacan metadata from the results panel

= AI configuration =

Connectors and credentials are managed in **Settings → Connectors**. The plugin relies on WordPress to choose an appropriate connector/model for each request.

= Customization =

Configure a default prompt preamble, per-collection overrides on the collection edition form, suggested preamble templates, features such as EXIF extraction, and clear cache from **Tainacan → Others → AI Tools**.

Each field uses the metadata slug as JSON key with shape `{ "value": "...", "evidence": "..." }`. The plugin appends the field list, response format, and file-type evidence rules at analysis time. Field blocks include optional guidance from metadata description/placeholder and optional constraints from metadata settings (for example required, max_items, min/max/step, max_length, allowed_values, and taxonomy/relationship hints). For taxonomy fields, allowed_values can include a ranked list of existing terms. Developers can customize each field block with the `tainacan_ai_extraction_field` filter; the plugin uses this same filter internally for built-in type hints.

== Installation ==

= Automatic Installation =

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "Tainacan AI"
3. Click **Install Now** and then **Activate**
4. Set up **Settings → Connectors** for AI access, then open **Tainacan → Others → AI Tools** for plugin options

= Manual Installation =

1. Upload the `tainacan-ai` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Configure **Settings → Connectors**, then **Tainacan → Others → AI Tools** as needed

= Requirements =

* WordPress 7.0 or higher (WordPress AI / Connectors)
* PHP 8.0 or higher
* Tainacan plugin (version 1.0 or higher)
* At least one AI connector configured in **Settings → Connectors** (exact providers depend on your site)

= Optional Dependencies =

* **EXIF Extension**: For automatic EXIF data extraction from images
* **Imagick Extension**: For visual PDF analysis (converts PDF pages to images)
* **Ghostscript**: Alternative method for PDF to image conversion
* **PDF Parser Library**: Install via Composer in the plugin directory for PDF text extraction

== Frequently Asked Questions ==

= Do I need the Tainacan plugin? =

Yes. Tainacan AI requires the [Tainacan](https://wordpress.org/plugins/tainacan/) plugin to be installed and active.

= Where do I configure API keys? =

In **Settings → Connectors** (WordPress 7.0+). The Tainacan AI settings page is for prompts, features, and cache.

= Which AI model or provider is used? =

That depends on your **Connectors** configuration and WordPress AI. This plugin does not expose a separate vendor picker; routing is handled by WordPress.

= How much does it cost? =

Any pricing depends on the services behind your connectors (hosted APIs, etc.).

= Can I customize what metadata is extracted? =

Yes. All collection metadata is included by default. Uncheck **Exclude from AI extraction** on a metadatum to omit it. You can also set default and per-collection prompts under **AI Tools** and on each collection edition form.

= Does it work with scanned PDFs? =

Often yes, when Imagick or Ghostscript is available and your connector supports the required modality—but results depend on environment and connector capabilities.

= Is my data sent to external services? =

If your connector uses a remote API, document content may be sent according to that service's terms. For fully local setups, that depends on your connector. The plugin can integrate with the **WP Consent API** when the consent option is enabled.

= Can I use multiple AI providers? =

You can configure multiple connectors in WordPress; this plugin does not maintain its own separate list of provider API keys.

== Screenshots ==

1. AI Tools settings page (prompts, features, cache)
2. Collection edit form with per-collection prompt override
3. Metadatum form with extraction opt-out toggle
4. Document analysis in the item edit form

== Changelog ==

= 0.2.0 =
* Redesigned sidebar interface with improved loading state, error handling, and debug information
* Advanced debugging mode for prompt visibility and per-run overrides
* Document HTML content filtering
* Settings for maximum document characters and PDF visual pages

= 0.1.0 =
* WordPress AI client and Connectors integration
* Per-metadatum extraction opt-out and dynamic prompt field lists
* Evidence `{ value, evidence }` schema appended at analysis time
* Item form fill actions keyed by metadata slug

== Upgrade Notice ==

= 0.1.0 =
Initial public release. Requires WordPress 7.0+, PHP 8.0+, and Tainacan 1.0+.

== Development ==

* **GitHub Repository**: [https://github.com/tainacan/tainacan-ai](https://github.com/tainacan/tainacan-ai)
* **Issues**: [GitHub Issues](https://github.com/tainacan/tainacan-ai/issues)
* **License**: GPLv2 or later

== Support ==

For support, feature requests, or bug reports, please visit the [GitHub repository](https://github.com/tainacan/tainacan-ai).

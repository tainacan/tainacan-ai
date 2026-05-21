=== Tainacan AI ===
Contributors: Sigismundo, tainacan, wetah, daltonmartins, vnmedeiros
Tags: tainacan, ai, cataloging, museums, image-analysis
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated metadata extraction in Tainacan using WordPress AI and Connectors. Prompts, mapping, and analysis are integrated into Tainacan.

== Description ==

Tainacan AI extends the [Tainacan](https://wordpress.org/plugins/tainacan/) plugin with AI-assisted metadata extraction from images and documents. It is intended for museums, archives, libraries, and digital repositories that want to speed up cataloging while keeping control of prompts and field mapping.

**AI access** is provided by **WordPress AI** through **Settings → Connectors** (WordPress 7.0+). This plugin does not store API keys for OpenAI, Gemini, or other vendors in its own settings screen; which model or service runs depends on your site’s connector configuration.

= Key Features =

* **WordPress AI & Connectors**: Uses the site’s configured AI connectors
* **Image analysis**: Extract structured metadata from images when your connector supports it
* **Document analysis**: Extract bibliographic-style or custom JSON fields from PDFs, TXT, and HTML
* **Custom prompts per collection**: A single prompt per collection to guide extraction for any supported file type
* **Metadata mapping**: Map AI output keys to Tainacan metadata for fill workflows
* **EXIF data extraction**: Optional technical metadata from image files (when the server supports it)
* **PDF support**: Text extraction via bundled parser; optional visual analysis depends on Imagick/Ghostscript and the connector
* **Smart caching**: Reduce repeat analysis with caching and a manual clear action
* **WP Consent API**: Optional consent gate for AI-powered actions (when enabled in settings)

= File types =

The plugin will attempt analysis only for attachments whose MIME type it recognizes (JPEG, PNG, GIF, WebP, PDF, plain text, HTML, and Word documents). Other types are rejected before any AI request runs.

Whether a supported file is fully processed depends on your setup—not on this list alone:

* **Images** need a connector model that accepts image input (check status under **Tainacan → AI Tools**).
* **PDFs** use text extraction when possible; visual analysis of scanned PDFs additionally requires Imagick or Ghostscript and a vision-capable connector.
* **EXIF** metadata is optional and requires the PHP EXIF extension.

= How It Works =

1. Configure **AI connectors** under **Settings → Connectors** in WordPress
2. Optionally set default prompts, features, and mapping under **Tainacan → Others → AI Tools**
3. Upload an image or document to a Tainacan item
4. Click **Analyze Document** in the item edit form
5. Review extracted fields and map or fill Tainacan metadata

= AI configuration =

Connectors and credentials are managed in **Settings → Connectors**. The plugin relies on WordPress to choose an appropriate connector/model for each request; you do not pick a legacy “provider card” inside Tainacan AI.

= Customization =

Configure a default analysis prompt, per-collection overrides on the collection edition form, suggested prompt templates, field mapping, features such as EXIF extraction, and clear cache from **Tainacan → Others → AI Tools**.

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

In **Settings → Connectors** (WordPress 7.0+). The Tainacan AI settings page is for prompts, features, cache, and mapping.

= Which AI model or provider is used? =

That depends on your **Connectors** configuration and WordPress AI. This plugin does not expose a separate vendor picker; routing is handled by WordPress.

= How much does it cost? =

Any pricing depends on the services behind your connectors (hosted APIs, etc.).

= Can I customize what metadata is extracted? =

Yes. You can configure default prompts, per-collection overrides on each collection's edition form, and map AI field names to Tainacan metadata from **AI Tools**.

= Does it work with scanned PDFs? =

Often yes, when Imagick or Ghostscript is available and your connector supports the required modality—but results depend on environment and connector capabilities.

= Is my data sent to external services? =

If your connector uses a remote API, document content may be sent according to that service’s terms. For fully local setups, that depends on your connector. The plugin can integrate with the **WP Consent API** when the consent option is enabled.

= Can I use multiple AI providers? =

You can configure multiple connectors in WordPress; this plugin does not maintain its own separate list of provider API keys.

== Screenshots ==

1. AI Tools settings page (prompts, features, cache)
2. Collection edit form with per-collection prompt override
3. Metadata mapping interface
4. Document analysis in the item edit form

== Changelog ==

= 0.1.0 =
* Migrates to using Core's AI client and Connectors APIs
* Removes legacy in-plugin providers (OpenAI, Gemini, DeepSeek, Ollama); AI access is via Settings → Connectors only
* Removes custom usage logging in favor of WordPress AI Request Logs (when enabled)
* Cleans up the UI to match Tainacan Admin Style

= 0.0.3 =
* Improved document detection in item edit form (less DOM mutations using Tainacan hooks)
* Added no document found message to item edit form
* Makes more strings translatable in the Javascript side

= 0.0.2 =
* UI improvements to match Tainacan Admin Style
* Detects if document is of type attachment before showing the analyze button

= 0.0.1 =
* Initial release
* Support for OpenAI, Google Gemini, DeepSeek, and Ollama (legacy in-plugin provider configuration)
* Image and document analysis
* Custom prompts per collection
* Metadata mapping
* EXIF extraction
* PDF support (text and visual)
* REST API endpoints
* WP Consent API integration

== Upgrade Notice ==

= 0.1.0 =
* Migrates to using Core's AI client and Connectors APIs (only works with WordPress 7.0+)

= 0.0.3 =
* Improved document detection in item edit form.

= 0.0.2 =
Better integration with Tainacan Admin Style.

= 0.0.1 =
Initial release. Requires WordPress 6.5+, PHP 8.0+, and Tainacan 1.0+.

== Development ==

* **GitHub Repository**: [https://github.com/tainacan/tainacan-ai](https://github.com/tainacan/tainacan-ai)
* **Issues**: [GitHub Issues](https://github.com/tainacan/tainacan-ai/issues)
* **License**: GPLv2 or later

== Support ==

For support, feature requests, or bug reports, please visit the [GitHub repository](https://github.com/tainacan/tainacan-ai).

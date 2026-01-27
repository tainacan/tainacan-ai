=== Tainacan AI ===
Contributors: Sigismundo, tainacan, wetah, daltonmartins, vnmedeiros
Tags: tainacan, ai, cataloging, museums, image-analysis
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated metadata extraction in Tainacan using AI. Supports multiple providers including OpenAI, Google Gemini, DeepSeek, and Ollama (local).

== Description ==

Tainacan AI extends the Tainacan plugin with powerful artificial intelligence capabilities for automated metadata extraction from images and documents. Perfect for museums, archives, libraries, and digital repositories looking to accelerate cataloging workflows.

= Key Features =

* **Multiple AI Providers**: Choose from OpenAI (GPT-4o with Vision), Google Gemini, DeepSeek (cost-effective alternative), or Ollama (free local deployment)
* **Image Analysis**: Extract detailed metadata from images including artistic techniques, materials, creation dates, and conservation status
* **Document Analysis**: Extract bibliographic information, keywords, abstracts, and structured data from PDFs, TXT, and HTML files
* **Custom Prompts per Collection**: Configure specialized prompts for different types of collections and metadata schemas
* **Automatic Metadata Mapping**: Map AI-extracted fields to your Tainacan metadata automatically
* **EXIF Data Extraction**: Automatic extraction of technical metadata from image files
* **PDF Support**: Text extraction and visual analysis for scanned PDF documents
* **Smart Caching**: Avoid repeated API calls with intelligent caching system
* **Usage Tracking**: Monitor API usage, costs, and success rates
* **REST API**: Integrate with external systems via REST endpoints
* **WP Consent API**: GDPR-compliant data handling integration

= Supported Formats =

* **Images**: JPG, PNG, GIF, WebP
* **Documents**: PDF (with text and visual analysis), TXT, HTML

= How It Works =

1. Upload an image or document to a Tainacan item
2. Click "Analyze Document" in the item edit form
3. AI extracts structured metadata based on your prompts
4. Review and map extracted fields to Tainacan metadata
5. Fill metadata fields automatically or manually

= AI Providers =

* **OpenAI**: GPT-4o with Vision support for images and documents
* **Google Gemini**: Gemini 1.5 Pro with Vision capabilities
* **DeepSeek**: Cost-effective alternative (text-only documents)
* **Ollama**: Free, local AI deployment with models like Llama, Mistral, and LLaVA

= Customization =

Configure custom prompts for each collection, enabling domain-specific metadata extraction tailored to your cataloging needs. Map AI output to your Tainacan metadata schema for seamless integration.

== Installation ==

= Automatic Installation =

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "Tainacan AI"
3. Click **Install Now** and then **Activate**
4. Navigate to **Tainacan > AI Tools** to configure your API key

= Manual Installation =

1. Upload the `tainacan-ai` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Configure your AI provider API key in **Tainacan > AI Tools**

= Requirements =

* WordPress 6.5 or higher
* PHP 8.0 or higher
* Tainacan plugin (version 1.0 or higher)
* An API key from one of the supported AI providers:
  * OpenAI: Get your key at [platform.openai.com](https://platform.openai.com/api-keys)
  * Google Gemini: Get your key at [Google AI Studio](https://aistudio.google.com/app/apikey)
  * DeepSeek: Get your key at [platform.deepseek.com](https://platform.deepseek.com/api_keys)
  * Ollama: Install locally from [ollama.com](https://ollama.com/download)

= Optional Dependencies =

* **EXIF Extension**: For automatic EXIF data extraction from images
* **Imagick Extension**: For visual PDF analysis (converts PDF pages to images)
* **Ghostscript**: Alternative method for PDF to image conversion
* **PDF Parser Library**: Included with the plugin via Composer

== Frequently Asked Questions ==

= Do I need a Tainacan plugin? =

Yes, Tainacan AI requires the [Tainacan](https://wordpress.org/plugins/tainacan/) plugin to be installed and active.

= Which AI provider should I use? =

* **OpenAI GPT-4o**: Best overall quality, supports images and documents (paid)
* **Google Gemini**: Excellent quality, good value, supports images and documents (paid)
* **DeepSeek**: Most cost-effective, text-only documents (paid)
* **Ollama**: Free but requires local setup, supports images with vision models (free, self-hosted)

= How much does it cost? =

* OpenAI and Google Gemini charge per API call based on tokens used
* DeepSeek offers competitive pricing for text analysis
* Ollama is completely free but requires local infrastructure
* The plugin includes cost tracking to monitor your usage

= Can I customize what metadata is extracted? =

Yes! You can configure custom prompts for each collection, specifying exactly what fields and information you want extracted. The plugin also supports metadata mapping to automatically populate Tainacan fields.

= Does it work with scanned PDFs? =

Yes, if you have Imagick or Ghostscript installed, the plugin can convert scanned PDF pages to images for visual analysis with vision-capable AI models.

= Is my data sent to external services? =

When using OpenAI, Google Gemini, or DeepSeek, your files are sent to their respective APIs for analysis. When using Ollama (local), all processing happens on your server. The plugin integrates with WP Consent API for GDPR compliance.

= Can I use multiple AI providers? =

Yes, you can switch between providers at any time in the plugin settings. Each provider can be configured independently.

== Screenshots ==

1. AI Tools settings page with provider selection
2. Custom prompt configuration for collections
3. Metadata mapping interface
4. Document analysis in item edit form
5. Usage statistics dashboard

== Changelog ==

= 0.0.3 =
* Improved document detection in item edit form (less DOM mutations using Tainacan hooks)
* Added no document found message to item edit form
* Makes more strings translatable in the Javascript side

= 0.0.2 =
* UI improvements to match Tainacan Admin Style
* Detects if document is of type attachment before showing the analyze button

= 0.0.1 =
* Initial release
* Support for OpenAI, Google Gemini, DeepSeek, and Ollama
* Image and document analysis
* Custom prompts per collection
* Metadata mapping
* EXIF extraction
* PDF support (text and visual)
* Usage tracking and cost estimation
* REST API endpoints
* WP Consent API integration

== Upgrade Notice ==

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

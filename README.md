# Tainacan AI

WordPress plugin for integrating Tainacan with AI APIs (OpenAI, Gemini, DeepSeek, Ollama). These allows some features such as extracting metadata from uploaded content.

## Features

- **Multiple AI Providers**: Support for OpenAI, Google Gemini, DeepSeek, and Ollama (local)
- **Compatible with Tainacan 1.0+**: Uses new `Pages` and `Admin Form Hooks` APIs
- **Modern Interface**: New color palette and smooth animations
- **Better UX**: Analysis button integrated directly in item form
- **REST API**: Endpoints for external integration
- **Smart Cache**: Cache system with manual invalidation
- **Custom Prompts**: Per-collection prompt customization
- **Metadata Mapping**: Automatic mapping of AI-extracted fields to Tainacan metadata
- **EXIF Extraction**: Automatic extraction of EXIF data from images
- **PDF Support**: Text extraction and visual analysis for PDFs

## Installation

1. Upload the `tainacan-ai` folder to `/wp-content/plugins/`
2. Run `composer install` in the plugin folder (for PDF support)
3. Activate the plugin in WordPress
4. Configure your API key in **Tainacan > AI Tools**

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

# Production build (minified assets)
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

Built assets are output to `build/`:
- JavaScript: `build/js/*.min.js`
- CSS: `build/css/*.min.css`

The plugin automatically detects built assets and uses them when available, falling back to source files in `src/` for development.

### WordPress.org Submission

When preparing a release for WordPress.org:

1. Run `npm run build` to generate production assets
2. Run `npm run plugin-zip` to create a distribution-ready ZIP
3. The `.distignore` file ensures only necessary files are included

**Important:** The `.distignore` file ensures only necessary files are included. The ZIP will contain:
- `includes/` directory with all PHP classes and admin templates
- `lib/` directory (embedded third-party libraries)
- `languages/` directory (translations)
- `build/` directory with compiled JS/CSS
- `tainacan-ai.php` (main plugin file)
- `readme.txt` (WordPress.org readme)

## Requirements

- WordPress 6.5+
- PHP 8.0+
- Tainacan 1.0+
- AI Provider API key (OpenAI, Gemini, DeepSeek, or Ollama)

## Usage

1. Configure your API key in plugin settings
2. Edit a Tainacan item with an attached document
3. In the "AI Metadata Extractor" section, click **Analyze Document**
4. Copy extracted metadata to desired fields or use automatic filling

## Supported Formats

**Images**: JPG, PNG, GIF, WebP  
**Documents**: PDF, TXT, HTML

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
│   ├── AdminPage.php       # Admin page class (Tainacan Pages API)
│   ├── ItemFormHook.php    # Form integration (Admin Form Hooks)
│   ├── DocumentAnalyzer.php # Document analysis
│   ├── API.php             # REST API endpoints
│   ├── CollectionPrompts.php # Custom prompts manager
│   ├── UsageLogger.php     # Usage statistics logger
│   ├── ExifExtractor.php   # EXIF data extractor
│   ├── AI/                 # AI provider implementations
│   │   ├── AbstractAIProvider.php
│   │   ├── AIProviderFactory.php
│   │   └── Providers/      # Provider classes (OpenAI, Gemini, etc.)
│   └── admin/              # Admin-specific templates
│       └── admin-page.php  # Settings page template
├── lib/                    # Embedded third-party libraries
│   └── PdfParser/          # PDF parsing library
├── src/                    # Source assets (not included in ZIP)
│   ├── css/                # Source CSS files
│   └── js/                 # Source JavaScript files
├── build/                  # Built assets (included in ZIP)
│   ├── css/                # Compiled CSS files
│   └── js/                 # Compiled JavaScript files
└── languages/              # Translations
```

## License

GPL v2 or later

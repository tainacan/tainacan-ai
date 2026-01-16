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
├── tainacan-ai.php         # Main file
├── src/
│   ├── AdminPage.php       # Admin page (Tainacan Pages API)
│   ├── ItemFormHook.php    # Form integration (Admin Form Hooks)
│   ├── DocumentAnalyzer.php # Document analysis
│   ├── API.php             # REST API
│   ├── CollectionPrompts.php # Custom prompts manager
│   ├── UsageLogger.php     # Usage statistics logger
│   ├── ExifExtractor.php   # EXIF data extractor
│   └── AI/                 # AI provider implementations
├── templates/
│   └── admin-page.php      # Settings page template
├── assets/
│   ├── css/
│   │   ├── admin.css       # Admin styles
│   │   └── item-form.css   # Form styles
│   └── js/
│       ├── admin.js        # Admin JavaScript
│       └── item-form.js    # Form JavaScript
└── languages/              # Translations
```

## License

GPL v2 or later

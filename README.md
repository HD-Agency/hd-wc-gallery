# HD WC Gallery

A standalone, high-performance, and independent WooCommerce Product Gallery plugin designed to
replace the default WooCommerce gallery structure with modern frontend components.

## Version

- **Current Version:** `1.0.0`
- **Requires PHP:** `8.1` or newer
- **Requires WooCommerce:** `7.0` or newer

## Key Features

1. **Modern Frontend Slider & Lightbox**
    - Built on top of **Swiper** (v14+) for a fully responsive, touch-friendly product slider.
    - Integrates **PhotoSwipe** (v5) for smooth lightbox overlays, touch gestures, and
      pinch-to-zoom.

2. **Product Video Integration**
    - Supports embedding videos (YouTube, Vimeo, or HTML5 custom video) directly into the gallery.

3. **Product Variation Galleries**
    - Assign unique and custom image galleries to specific WooCommerce product variations.

4. **REST API Endpoints**
    - Exposes REST routes to retrieve gallery assets, variations, and video URLs asynchronously.

5. **Polylang Compatibility**
    - Integrated with Polylang for multilingual translation support on media and variation
      structures.

6. **Vite Tooling**
    - Bundled and optimized via Vite for fast performance and minimal asset overhead.

## Directory Structure

```text
├── assets/             # Built production assets (CSS, JS)
├── src/
│   ├── API/            # REST API Routes and endpoint logic
│   ├── Admin/          # WooCommerce admin fields & settings (videos, variation galleries)
│   ├── Core/           # Shared core hooks & utility logic
│   ├── Frontend/       # Gallery custom renderers & script enqueuers
│   └── Integrations/   # Third-party integrations (Polylang)
├── resources/          # Source SCSS, TypeScript, and asset files
├── composer.json       # PHP dependencies & autoload configurations
├── package.json        # Frontend dependencies & build scripts
└── vite.config.ts      # Vite configuration file
```

## Setup & Installation

### 1. PHP Dependency Setup

Run composer to install and optimize class autolinking:

```bash
composer install --no-dev --optimize-autoloader
```

### 2. Frontend Build

Install Node dependencies and build production assets:

```bash
npm install
npm run build
```

## License

This project is licensed under the MIT License.

# Camp Weird Steel

Camp Weird Steel is a Burning Man art camp project, featuring a custom gallery, interactive web features, and Flarum forum integration. This repository contains the source code and assets for the camp's website and gallery system.

## Features
- Custom PHP-based website and gallery
- Flarum forum integration
- Responsive design with CSS and JavaScript assets
- Google Analytics integration
- Modular includes for easy site updates
- Gallery authentication and management

## Project Structure
```
includes/         # PHP includes for site sections (header, footer, navigation, gallery, etc.)
public/           # Public web assets (CSS, JS, images, gallery, forum)
storage/          # Cache, logs, sessions, views, and other storage
vendor/           # Composer dependencies
CHANGELOG.md      # Project changelog
composer.json     # Composer configuration
README.md         # Project documentation
site.php          # Main site entry point
```

## Getting Started
1. **Clone the repository:**
   ```bash
   git clone https://github.com/zinefer/campweirdsteel.git
   ```
2. **Install dependencies:**
   ```bash
   composer install
   ```
3. **Configure the site:**
   - Update configuration files in `includes/` and `public/gallery/` as needed.
   - Set up your web server to serve the `public/` directory.

## Gallery System
- Authentication and management handled via `includes/gallery/`
- Public gallery interface in `public/gallery/`

## Forum Integration
- Flarum forum located in `public/forum/`
- Extend or customize via `extend.php` and Flarum extensions in `vendor/flarum/`

## Contributing
Pull requests and issues are welcome! Please review the [CHANGELOG.md](CHANGELOG.md) and [LICENSE](LICENSE) before contributing.

## License
This project is licensed under the MIT License. See [LICENSE](LICENSE) for details.

## Contact
For questions or collaboration, reach out via the contact form on the website or open an issue on GitHub.


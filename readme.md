# Atlas - Audio Library Management System

## Overview
Atlas is a self-hosted audio library management and streaming platform. It provides automated metadata extraction, intelligent file organization, and a modern web interface for browsing and playing your audio collection.

## Core Features

### 🎵 **Audio-Focused Library Management**
- **Automated Metadata Extraction**: Processes audio files to extract ID3v1/ID3v2 tags, cover art, and technical information
- **Smart Organization**: Automatically organizes files by artists, albums, and metadata
- **Cover Art Management**: Extracts and manages album artwork with deduplication
- **File Status Tracking**: Monitors missing files, metadata extraction status, and files requiring review

### 🔍 **Advanced Search & Discovery**
- **Full-Text Search**: Powered by Typesense for fast, typo-tolerant search across metadata
- **Faceted Browsing**: Filter by artists, albums, years, genres, and file properties
- **Audio-Only Results**: Filters ensure only playable audio content is displayed

### 🎧 **Streaming & Playback**
- **Direct Audio Streaming**: Stream audio files directly from your library
- **Love/Like/Dislike System**: Rate and organize your favorite tracks
- **Seen Status Tracking**: Keep track of what you've listened to

### 📊 **Dashboard & Analytics**
- **File Statistics**: Visual breakdown of library composition and storage usage
- **Health Monitoring**: Track files needing attention (missing, no metadata, review required)
- **Space Usage Analysis**: Understand storage consumption by file type

### 👥 **User Management**
- **Multi-User Support**: User registration and authentication
- **Admin Controls**: Super admin role for user and system management
- **Individual Preferences**: Personal ratings and listening history

## Technology Stack
- **Backend**: Laravel 12 (PHP 8.2+)
- **Frontend**: Vue.js 3 with TypeScript and Inertia.js
- **UI Framework**: Tailwind CSS with Shadcn Vue components
- **Search Engine**: Typesense with Laravel Scout
- **Queue System**: Laravel Queues for background processing
- **Metadata Processing**: PHP-FFMpeg for audio file analysis
- **Authentication**: Laravel Breeze with Inertia.js

## Installation

### Prerequisites
- **PHP 8.2+** with extensions: `mbstring`, `xml`, `json`, `gd`
- **Composer** for PHP dependency management
- **Node.js 18+** and npm for frontend assets
- **Database**: MySQL 8.0+ or PostgreSQL 13+ or SQLite 3.35+
- **Queue Worker**: Redis (recommended) or database for job queuing
- **Search Engine**: Typesense server for full-text search
- **Storage**: Local file system or cloud storage for audio files

### Setup Steps

1. **Clone the repository**
   ```bash
   git clone https://github.com/wyxos/atlas.git
   cd atlas
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Install JavaScript dependencies**
   ```bash
   npm install
   ```

4. **Environment configuration**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. **Configure services in `.env`**
   ```env
   # Database
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_DATABASE=atlas
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   
   # Typesense Search
   SCOUT_DRIVER=typesense
   TYPESENSE_API_KEY=your_typesense_key
   TYPESENSE_HOST=localhost
   TYPESENSE_PORT=8108
   
   # Queue (for metadata processing)
   QUEUE_CONNECTION=redis  # or database
   ```

6. **Run database migrations**
   ```bash
   php artisan migrate
   ```

7. **Build frontend assets**
   ```bash
   npm run dev  # or npm run build for production
   ```

8. **Start the queue worker** (separate terminal)
   ```bash
   php artisan queue:work
   ```

9. **Start the development server**
   ```bash
   php artisan serve
   ```

## Usage

### Adding Audio Files to Your Library

1. **Add files to your storage location** (configured in `.env`)
2. **Extract metadata from audio files**:
   ```bash
   # Process all audio files
   php artisan files:extract-metadata
   
   # Process a specific file
   php artisan files:extract-metadata --file=123
   ```

3. **Translate extracted metadata**:
   ```bash
   # Translate all extracted metadata
   php artisan files:translate-metadata
   
   # Force reprocessing of existing metadata
   php artisan files:translate-metadata --force
   
   # Process a specific file
   php artisan files:translate-metadata --file=123
   ```

### Development Commands

- **Start development environment**: `composer run dev`
- **Run with SSR**: `composer run dev:ssr`
- **Run tests**: `composer run test` or `php artisan test`
- **Watch frontend changes**: `npm run dev`
- **Build for production**: `npm run build`
- **Queue worker**: `php artisan queue:work`

## Roadmap

### Near Term
- [ ] **Playlist Management**: Create and manage custom playlists
- [ ] **Enhanced Audio Player**: Improved playback controls and queue management
- [ ] **Batch Operations**: Bulk metadata editing and file operations
- [ ] **Mobile Responsive Design**: Better mobile/tablet experience

### Medium Term
- [ ] **Advanced Search**: More sophisticated filtering and faceted search
- [ ] **Metadata Editing**: In-app metadata correction and enhancement
- [ ] **File Import Wizards**: Guided setup for new libraries
- [ ] **Docker Support**: Containerized deployment options

### Long Term
- [ ] **Plugin System**: Extensible architecture for custom features
- [ ] **Multiple Libraries**: Support for separate music collections
- [ ] **Last.fm Integration**: Scrobbling and external metadata sources
- [ ] **API Documentation**: RESTful API for external integrations
- [ ] **Collaborative Features**: Shared libraries and social features

## Screenshots

![Dashboard](docs/images/dashboard.png)
*Atlas Dashboard showing file statistics and distribution*
![Audio Player](docs/images/audio-player.png)
*Audio player interface with playback controls and metadata*

## Contributing

We welcome contributions! Here's how you can help:

1. **Fork the repository**
2. **Create a feature branch** (`git checkout -b feature/amazing-feature`)
3. **Commit your changes** (`git commit -m 'Add some amazing feature'`)
4. **Push to the branch** (`git push origin feature/amazing-feature`)
5. **Open a Pull Request**

Please make sure to:
- Follow the existing code style
- Write tests for new features
- Update documentation as needed
- Test your changes thoroughly

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

If you encounter any issues or have questions, please [open an issue](https://github.com/wyxos/atlas/issues) on GitHub.

## Acknowledgments

- Built with [Laravel](https://laravel.com/) and [Vue.js](https://vuejs.org/)
- UI components from [Shadcn Vue](https://www.shadcn-vue.com/)
- Search powered by [Typesense](https://typesense.org/)

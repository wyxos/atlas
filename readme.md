# ATLAS

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel)](https://laravel.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D?logo=vue.js)](https://vuejs.org)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php)](https://php.net)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6?logo=typescript)](https://typescriptlang.org)

*Your media. Your server. Your rules.*

ATLAS is a work-in-progress, self-hosted media server for people who want reliable organization, fast search, and direct streaming of their own libraries.

## Why ATLAS?

- I built ATLAS because reliability and simplicity mattered more than knobs and toggles.
- I wanted better organization and sorting without waiting on upstream features.
- I care about first-class content browsing with blacklist and curation.
- I prefer one-click or shortcut-driven workflows to download and manage content.
- I want content permanence: keep what you love even if platforms or creators remove it.

Existing media servers like Plex, Jellyfin, and Emby are powerful. ATLAS focuses on reliability, frictionless organization, and content browsing.

## Status: Work in Progress

ATLAS is under active development. Features ship incrementally and may change. Feedback is welcome. Open issues and ideas here: https://github.com/wyxos/atlas/issues

## What Works Today

### Core Features
- **Audio Library**: Stream music with metadata (ID3, cover art), organize by artist/album, create playlists
- **Photo Management**: Browse, curate, and react to images with favorites, likes, and blacklisting
- **Reel/Video Support**: Short-form video viewing with reactions and moderation
- **Smart Search**: Full-text search across your library when Typesense is enabled
- **Download Manager**: Track and manage content downloads with pause/resume
- **Content Curation**: Blacklist and moderation rules to filter unwanted content

### User Experience
- Multi-user accounts with role-based access (admin controls)
- Reactions system (favorites, likes, funny, dislike) across all media types
- Real-time updates via Laravel Reverb
- Dashboard with library statistics and storage insights
- Spotify integration for enhanced music library features

## Screenshots

*Coming soon: Screenshots will be added to showcase the dashboard, audio player, and browse interface.*

## Usage Examples

### Getting Started

1. **First User Setup**
   - Navigate to http://localhost:8080
   - Register your account (first user becomes admin)
   - Configure storage paths in settings

2. **Adding Media**
   - Place files in the configured storage directory
   - Files are automatically discovered and indexed
   - Metadata is extracted from ID3 tags (audio) and EXIF (photos)

3. **Managing Your Library**
   - **Browse**: Navigate photos, reels, and audio in the browse interface
   - **React**: Mark favorites, likes, or dislike content
   - **Blacklist**: Set moderation rules to filter unwanted content
   - **Playlists**: Create and manage audio playlists
   - **Search**: Use Typesense search to find specific content

4. **Admin Features**
   - Manage user accounts and permissions
   - Configure storage paths and scanning
   - View download queue and system health
   - Monitor library statistics on the dashboard

## Roadmap

### Active Development

- [ ] Enhanced audio player UI (improved queue, playback controls)
- [ ] In-app metadata editing
- [ ] Batch file operations
- [ ] Mobile-responsive interface improvements
- [ ] Advanced playlist features (smart playlists, shared playlists)

### Future

- [ ] Plugin system for extensibility
- [ ] Multi-library support
- [ ] Advanced content recommendations
- [ ] Mobile app (iOS/Android)


## Contributing

Want to help improve ATLAS? We welcome contributions of all kinds!

- **Found a bug?** [Open an issue](https://github.com/wyxos/atlas/issues)
- **Have an idea?** [Start a discussion](https://github.com/wyxos/atlas/issues)
- **Ready to code?** See [CONTRIBUTING.md](CONTRIBUTING.md) for developer setup and guidelines

Pull requests are welcome! Please check [CONTRIBUTING.md](CONTRIBUTING.md) for coding standards, testing requirements, and the development workflow.

## License & Acknowledgments

ATLAS is open-source software licensed under the [MIT License](LICENSE). You're free to use, modify, and distribute this software according to the license terms.

- Created by [Wyxos](https://wyxos.com)
- Built with [Laravel](https://laravel.com/) and [Vue.js](https://vuejs.org/)
- UI components from [Shadcn Vue](https://www.shadcn-vue.com/)
- Search powered by [Typesense](https://typesense.org/)

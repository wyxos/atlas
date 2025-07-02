# Atlas - Content Management System

## Overview
Atlas is a self-hosted application designed to provide a unified interface for browsing and managing content from various sources. It allows users to access and organize media content in a standardized format with a focus on user experience.

## Features
- **Multi-Source Content**: Browse content from various sources including:
  - Video files
  - YouTube
  - Images
  - Music
- **Enhanced User Experience**:
  - Content seen status tracking
  - Ability to blacklist unwanted content
  - One-click downloads
  - Content tagging system
  - AI-powered features
  - Library indexing
- **User Management**:
  - User registration and authentication
  - User role management (including super admin)
  - User activity tracking

## Technology Stack
- **Backend**: Laravel PHP framework
- **Frontend**: Vue.js with TypeScript
- **UI Components**: Shadcn Vue
- **Authentication**: Laravel Breeze/Inertia

## Installation

### Prerequisites
- PHP 8.1 or higher
- Composer
- Node.js and npm
- MySQL or PostgreSQL database

### Setup Steps
1. Clone the repository
   ```
   git clone https://github.com/your-username/atlas.git
   cd atlas
   ```

2. Install PHP dependencies
   ```
   composer install
   ```

3. Install JavaScript dependencies
   ```
   npm install
   ```

4. Create and configure environment file
   ```
   cp .env.example .env
   php artisan key:generate
   ```

5. Configure your database in the `.env` file

6. Run migrations
   ```
   php artisan migrate
   ```

7. Build frontend assets
   ```
   npm run dev
   ```

8. Start the development server
   ```
   php artisan serve
   ```

## Development

### Commands
- Run development server: `php artisan serve`
- Watch for frontend changes: `npm run dev`
- Run tests: `php artisan test`
- Create database backup: `php artisan db:backup`

## Contributing
Contributions are welcome! Please feel free to submit a Pull Request.

## License
[License information]

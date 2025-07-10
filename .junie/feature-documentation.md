# Atlas Feature Documentation

## Audio Reaction System

### Overview
The Atlas application includes a comprehensive reaction system for audio files that allows users to express their feelings about tracks through various reactions. This system is integrated throughout the audio interface and provides both UI interactions and backend persistence.

### Available Reactions
The system supports four types of reactions:

1. **Love** (Heart icon) - Mark as favorite/loved
2. **Like** (Thumbs Up icon) - Mark as liked  
3. **Dislike** (Thumbs Down icon) - Mark as disliked
4. **Laughed At** (Laugh icon) - Mark as funny/amusing

### Database Schema
Each reaction is stored in the `files` table with the following columns:

#### Boolean Status Columns
- `loved` (boolean, default: false)
- `liked` (boolean, default: false) 
- `disliked` (boolean, default: false)
- `laughed_at` (boolean, default: false)

#### Timestamp Columns
- `loved_at` (timestamp, nullable)
- `liked_at` (timestamp, nullable)
- `disliked_at` (timestamp, nullable)
- `laughed_at_at` (timestamp, nullable)

### Business Logic
The reaction system implements **mutually exclusive reactions** - only one reaction can be active at a time per file:

- When a user sets any reaction to true, all other reactions are automatically set to false
- When a reaction is toggled off, only that reaction is affected (others remain false)
- Timestamp fields are set to `now()` when a reaction is activated, and `null` when deactivated

### Backend Implementation

#### Routes
All reaction routes follow RESTful conventions:
```php
Route::post('audio/{file}/love', [AudioController::class, 'toggleLove'])->name('audio.love');
Route::post('audio/{file}/like', [AudioController::class, 'toggleLike'])->name('audio.like');
Route::post('audio/{file}/dislike', [AudioController::class, 'toggleDislike'])->name('audio.dislike');
Route::post('audio/{file}/laughed-at', [AudioController::class, 'toggleLaughedAt'])->name('audio.laughed-at');
```

#### Controller Methods
Each reaction has a dedicated toggle method in `AudioController`:
- `toggleLove(File $file)`
- `toggleLike(File $file)`
- `toggleDislike(File $file)`
- `toggleLaughedAt(File $file)`

All methods:
- Toggle the specific reaction boolean
- Set/unset the corresponding timestamp
- Reset other reactions when the current one is activated
- Return JSON response for AJAX requests
- Support both JSON and form requests

#### Model Configuration
The `File` model includes:
- All reaction fields in `$fillable` array
- Proper casting for boolean and datetime fields
- Relationships for covers, artists, albums, metadata

### Frontend Implementation

#### Components
**AudioListItem.vue**
- Displays reaction buttons for each audio file
- Handles click events and prevents event propagation
- Shows visual feedback (colors, filled icons) for active reactions
- Supports drag-and-drop cover image replacement

**Audio.vue (Main Page)**
- Manages reaction state in `loadedFiles` object
- Implements optimistic UI updates
- Synchronizes reactions with global audio store
- Handles error rollback on failed requests

#### UI Design
- **Love**: Red heart icon with red background when active
- **Like**: Blue thumbs up icon with blue background when active  
- **Dislike**: Gray thumbs down icon with gray background when active
- **Laughed At**: Yellow laugh icon with yellow background when active

#### State Management
- Local state in `loadedFiles` object for immediate UI feedback
- Global audio store synchronization for currently playing file
- Optimistic updates with error rollback functionality

### Integration Points

#### Global Audio Player
The reaction system integrates with the global audio player:
- Current file reactions are synchronized between list view and player
- Reaction changes update both the file list and player state
- Player controls can trigger reaction updates
- All four reaction buttons (love, like, dislike, laughed_at) are available in the desktop player
- Mobile player focuses on playback controls and doesn't include reaction buttons

#### Search and Filtering
- Reaction status is included in file details API responses
- Search results include reaction information
- Future filtering by reaction status is supported by the data structure

### Testing
Comprehensive test coverage includes:
- Toggle functionality for each reaction type
- Mutual exclusion behavior
- Timestamp handling
- Error cases (non-existent files)
- JSON response validation
- Database state verification

### Migration History
- Initial reactions (love, like, dislike) were part of the original files table creation
- `laughed_at` reaction added via migration `2025_07_10_204429_add_laughed_at_to_files_table.php`

### Future Considerations
- Additional reaction types can be added following the same pattern
- Reaction analytics and statistics could be implemented
- Bulk reaction operations for playlists
- Reaction-based smart playlists
- Social features (sharing reactions, reaction feeds)

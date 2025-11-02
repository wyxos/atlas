# File Metadata Processing Commands

This document describes the Laravel commands and jobs for processing CivitAI file metadata.

## Commands

### 1. Process File Listing Metadata

Dispatches jobs to process CivitAI files and create container instances for posts and users.

```bash
php artisan files:process-listing-metadata
```

#### Options

- `--limit=N` - Limit the number of files to process
- `--queue=name` - Specify which queue to dispatch jobs to
- `--skip-processed` - Skip files that already have containers attached

#### Examples

```bash
# Process all files
php artisan files:process-listing-metadata

# Process only 1000 files
php artisan files:process-listing-metadata --limit=1000

# Skip files that already have containers
php artisan files:process-listing-metadata --skip-processed

# Dispatch to specific queue
php artisan files:process-listing-metadata --queue=file-processing

# Combine options
php artisan files:process-listing-metadata --limit=500 --skip-processed --queue=file-processing
```

#### What it does

For each CivitAI file with `listing_metadata`:
1. Checks for `postId` - creates/finds a **Post** container with referrer `https://civitai.com/posts/{postId}`
2. Checks for `username` - creates/finds a **User** container with referrer `https://civitai.com/user/{username}`
3. Attaches the file to both containers (avoids duplicates)

---

### 2. Fix Double-Encoded Metadata

Fixes files where `listing_metadata` has been incorrectly double-encoded as a JSON string.

```bash
php artisan files:fix-double-encoded-metadata
```

#### Options

- `--limit=N` - Limit the number of files to fix
- `--queue=name` - Specify which queue to dispatch jobs to

#### Examples

```bash
# Find and fix all double-encoded metadata
php artisan files:fix-double-encoded-metadata

# Fix only 100 files
php artisan files:fix-double-encoded-metadata --limit=100

# Dispatch to specific queue
php artisan files:fix-double-encoded-metadata --queue=metadata-fixes
```

#### What it does

1. Finds files where `listing_metadata` is stored as a JSON string instead of a proper JSON object
2. Decodes the double-encoded JSON back to a proper array
3. Saves the corrected metadata

#### Example of double-encoded data

**Before (wrong):**
```json
"{\"postId\":12345,\"username\":\"artist\"}"
```

**After (correct):**
```json
{"postId":12345,"username":"artist"}
```

---

## Jobs

### ProcessFileListingMetadataJob

Processes a single file's listing metadata and creates containers.

- **Timeout:** 300 seconds
- **Retries:** 3 attempts
- **Queue:** configurable via command

### FixDoubleEncodedListingMetadataJob

Fixes double-encoded metadata for a single file.

- **Timeout:** 300 seconds
- **Retries:** 3 attempts
- **Queue:** configurable via command

---

## Running Queue Workers

To process the dispatched jobs in parallel:

```bash
# Single worker
php artisan queue:work

# Multiple workers (in separate terminals/processes)
php artisan queue:work --queue=file-processing --tries=3
php artisan queue:work --queue=file-processing --tries=3
php artisan queue:work --queue=file-processing --tries=3
php artisan queue:work --queue=file-processing --tries=3
```

### Windows (PowerShell)

```powershell
# Start 4 workers in background
1..4 | ForEach-Object { Start-Process php -ArgumentList "artisan", "queue:work", "--queue=file-processing", "--tries=3" }
```

### Monitoring

```bash
# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

---

## Workflow

### Initial Setup

1. **Fix double-encoded metadata** (if needed):
   ```bash
   php artisan files:fix-double-encoded-metadata
   ```

2. **Start queue workers** (multiple terminals):
   ```bash
   php artisan queue:work --queue=default --tries=3
   ```

3. **Process files**:
   ```bash
   php artisan files:process-listing-metadata
   ```

### Incremental Processing

When adding new files, skip already-processed ones:

```bash
php artisan files:process-listing-metadata --skip-processed
```

---

## Database Structure

### Containers Table
- `id` - Primary key
- `type` - Container type ('Post' or 'User')
- `source` - Source platform ('CivitAI')
- `source_id` - External ID (post ID or username)
- `referrer` - URL to the source

### Container-File Pivot
- `container_id` - Foreign key to containers
- `file_id` - Foreign key to files
- Unique constraint on (container_id, file_id)

---

## Logging

All jobs log their actions to Laravel's log:

- **Info:** Successful operations (container creation, attachments)
- **Warning:** Skipped files, failed decoding attempts
- **Error:** Exceptions during processing

View logs at `storage/logs/laravel.log`

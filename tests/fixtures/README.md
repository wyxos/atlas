# Test Fixtures

This directory contains test fixtures for the Atlas application.

## Audio Files (`audio/`)

The `audio/` directory contains sample MP3 files downloaded from public domain sources for testing metadata extraction and translation functionality.

### Available Files

- `test_complete_metadata.mp3` - Classical music file with comprehensive metadata
- `test_minimal_tags.mp3` - Audio file with basic metadata tags
- `test_jazz_sample.mp3` - Jazz sample for testing different metadata formats
- `test_short_sample.mp3` - Short audio sample for quick testing

### Corresponding Metadata Fixtures

Each audio file has a corresponding JSON metadata fixture that simulates what would be extracted by the Node.js metadata extraction script:

- `metadata_complete.json` - Complete ID3v2.3 metadata with cover art
- `metadata_minimal.json` - Basic metadata with title and artist only  
- `metadata_id3v1.json` - Legacy ID3v1 format metadata
- `metadata_no_tags.json` - File with no metadata tags (for testing fallback behavior)

## Usage in Tests

The metadata processing tests (`tests/Feature/MetadataProcessingTest.php`) use these fixtures to:

1. **Test ExtractFileMetadata Job**: Uses a test subclass that reads from fixture JSON files instead of executing the Node.js script
2. **Test TranslateFileMetadata Job**: Verifies that metadata is properly translated from raw format into structured data
3. **Integration Testing**: Tests the complete workflow from extraction to translation

## Test Coverage

The test suite covers:

- ✅ Complete metadata extraction with ID3v2.3 tags
- ✅ Minimal metadata handling
- ✅ Legacy ID3v1 format support
- ✅ Cover art extraction and storage
- ✅ Files with no metadata (marked for review)
- ✅ Corrupted metadata handling
- ✅ Tag preservation during translation
- ✅ Error handling and graceful failures

## Copyright Notice

All audio files in this directory are from public domain sources:
- Internet Archive (archive.org)
- Creative Commons licensed content
- Test audio samples specifically created for testing purposes

These files are safe to use for testing and development purposes.

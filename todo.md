- [ ] Browse feature improvements
    - [ ] Browse
        - [ ] Evaluate other default feeds (Youtube, etc)
- [ ] File listing feature
    - [ ] List view
        - [ ] Display content from containers of the file
        - [ ] re-use fileviewer layout
- [ ] File scanning and processing
    - [ ] Image processing
        - [ ] Generate thumbnails for image files
        - [ ] Extract metadata from image files (dimensions, format, EXIF data, etc.)
    - [ ] Audio processing
        - [ ] Extract metadata from audio files (artist, album, duration, etc.)
        - [ ] Convert audio to MP3 format where relevant while preserving original file
        - [ ] Generate audio waveform visualization
        - [ ] Generate or retrieve cover art for audio files
        - [ ] Retrieve and store lyrics for audio files
    - [ ] Spotify integration
        - [ ] Implement Spotify playlist scanning and import functionality
    - [ ] Video processing
        - [ ] Convert video files to MP4 format for compatibility
        - [ ] Extract metadata from video files (duration, resolution, codec, etc.)
        - [ ] Generate thumbnail images for video files
        - [ ] Generate preview seekbar with keyframe thumbnails
        - [ ] Extract and store subtitles from video files
        - [ ] Generate video segments (HLS/DASH) to enable adaptive streaming


- [ ] Dashboard
    - [ ] Disliked and Unreacted files to exclude blacklisted files
    - [ ] Need stats to showcase files that are
        - [ ] Reacted but not on disk
        - [ ] Unreacted but on disk (Not downloaded, source local)
        - [ ] Downloaded but not on disk
        - [ ] Downloaded but not reacted
- [x] Extension
    - [x] Ability to delete download
    - [x] Ability to blacklist
    - [x] Outline links that were already visit/reacted when present
    - [x] Outline/border files that were already reacted with the relevant color
    - [x] Shortcut to open sheet
    - [x] Shortcut to react and close
    - [x] Sometimes, clicking on an image, opens a bigger version, the filereaction widget shows at times, and sometimes
      not, even though the new item appears in the sheet
    - [x] If I open the file url directly, I should still see the extension sheet and widget and ability to react .e.g
      blobl:https://.... the url becomes the referrer
    - [x] when reacting, reacted cta show spinner, other ctas disabled, once complete, show reaction as active
    - [x] reacting to an already reacted and downloaded file, prompt if to redownload before changing the reaction,
      confirm or cancel, will still update the reaction
    - [x] dislike/blacklist to a file that is already downloaded, should prompt if to delete the file then proceed with
      the dislike/blacklist
    - [x] Use clean dialog instead of alert
    - [x] Tested: `vendor/bin/pint --dirty`,
      `php artisan test --compact tests/Feature/ExtensionFilesCheckTest.php tests/Feature/ExtensionFilesStoreTest.php tests/Feature/ExtensionFilesReactTest.php tests/Feature/ExtensionFilesDeleteDownloadTest.php`,
      `npm run lint`, `npm run typecheck`, `npm run build:extension`, `npm run test -- resources/js/utils/file.test.ts`
    - [x] On deviant art for example, some images are clickable and opens a larger version. In some cases when that
      larger version opens in what seems to be a modal, the filereaction widget sometimes do not show up.
    - [x] For the filereaction widget, I previously said the following behavior is to be implemented and is expected to
      be consistent across both sheet and widget, which is, when a reaction is clicked, a spinner is displayed instead
      of the said icon for the interacted reaction during the request execution. Once completed, the reaction is then
      activated. During the request, other reactions are disabled.
    - [x] if I click on an image, and it opens the larger version in a modal, the image is not outlined as expected even
      though it was already reacted (the larger version)
    - [x] reloading a page with an image I already reacted to, doesn't show the border. Sometimes the border doesn't
      show either after the reaction is applied.
    - [x] Tested: `php artisan test --compact tests/Feature/ExtensionFilesCheckTest.php tests/Feature/ExtensionFilesStoreTest.php tests/Feature/ExtensionFilesReactTest.php tests/Feature/ExtensionFilesDeleteDownloadTest.php`,
      `npm run lint`, `npm run typecheck`, `npm run build:extension`
- [ ] Issues
    - [ ] Backfill old records for missing containers
    - [ ] Pill reaction does not exclude items flagged for dislike that are outside viewport (reacting from outside
      downloads those items)

# Atlas Media Downloader Extension

Load this folder as an unpacked extension in Chrome/Brave.

1. Open `chrome://extensions` (or `brave://extensions`).
2. Enable Developer Mode.
3. Click "Load unpacked" and select `extension/atlas-downloader`.
4. Open the extension options and set:
   - Atlas base URL (ex: `https://atlas.test`)
   - Extension token (must match `ATLAS_EXTENSION_TOKEN` in `.env`)
   - (Optional) Excluded domains (one per line) to disable the content script on specific sites

When browsing, press `Alt+A` to toggle the Atlas picker, select items, and queue downloads in your Atlas instance.
You can customize this shortcut in `chrome://extensions/shortcuts` (or `brave://extensions/shortcuts`).

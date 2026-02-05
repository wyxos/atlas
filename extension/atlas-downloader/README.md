# Atlas Media Downloader Extension

Load this folder as an unpacked extension in Chrome/Brave.

1. Open `chrome://extensions` (or `brave://extensions`).
2. Enable Developer Mode.
3. Click "Load unpacked" and select `extension/atlas-downloader`.
4. Open the extension options and set:
   - Atlas base URL (ex: `https://atlas.test`)
   - Extension token (must match `ATLAS_EXTENSION_TOKEN` in `.env`)

When browsing, hover large images (>= 450x450) or any video to reveal the Atlas button.
Click the button to queue the download in your Atlas instance.

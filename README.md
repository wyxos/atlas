<p align="center">
	<img src="public/favicon.svg" alt="Atlas" width="96" height="96" />
</p>

<h1 align="center">Atlas</h1>

<p align="center">
	<a href="https://youtu.be/g1Ogg5vivSM">Watch the demo video</a>
</p>

Setup guide: [docs/SETUP.md](docs/SETUP.md)

A self-hosted feed curating tool: scroll fast, react fast, block the junk, auto-save what is worth keeping.

It is also growing into a batch downloader and personal library with lightweight streaming.

The hard part is not finding content anymore. It is filtering it fast and keeping only what matters.

Atlas is built for quick, decisive keep/nope workflows, automated cleanup, and background saves so you can move through large streams without getting buried.

**What it does**

- Fast browsing of image and video feeds at scale
- One-click reactions to keep, discard, or flag items
- Automated background downloads for the things you want to keep
- Noise reduction through moderation rules and blacklists
- Separate tabs to keep different hunting sessions distinct

**Why it is different**

Atlas is not a traditional media library manager. It is for curation from chaotic, fast-moving sources:

- Prioritizes speed over catalog perfection
- Makes "keep vs skip" the primary action
- Uses your reactions and rules to cut repeats and spam
- Designed around discovery and collection, not just playback

**Do you run into**

- Large, noisy feeds where the good stuff is buried
- Slow keep/nope workflows that make curation a chore
- Repeats, spam, or low-signal items clogging your stream
- A growing backlog without a clean way to save the best items

Built to handle these.

**Current sources**

- CivitAI Images
- Wallhaven
- Local files you already have

More sources can be added over time.

## Docker

**Authentication Flow Update**

- Local email/password login is now enabled by default in the test environment.
- The `auth.local_enabled` config flag controls SSO redirection. In development and testing, set `AUTH_LOCAL_ENABLED=true` to use the standard login form.
- Remember-me functionality updates the user's `remember_token`.
- Successful login updates `last_login_at` timestamp.

## Docker

Run the entire stack with a single command:

```bash
./docker-setup.sh
```

This starts MariaDB, Redis, Typesense, Reverb (WebSockets), Horizon (queues), Nginx, and phpMyAdmin — all pre-configured.

- **App**: http://localhost:8080
- **phpMyAdmin**: http://localhost:8081

See [docs/SETUP.md](docs/SETUP.md) for detailed Docker instructions.

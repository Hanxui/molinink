<p align="center"><img src=".github/assets/logo.png" alt="MolinInk" width="320"></p>

<p align="center">A blog engine written in PHP. Install it and start writing.</p>

<p align="center"><sub>PHP 8.2+ · MySQL 8 · single entry point · AGPL-3.0</sub></p>

<p align="center"><a href="https://github.com/Hanxui/molinink/releases/latest">Download</a> · <a href="https://www.molinink.com">Website</a> · <a href="https://demo.molinink.com">Live demo</a></p>

<p align="center"><img src=".github/assets/screen-home.png" alt="Front page"></p>

## What this is

MolinInk (墨林) is a blog engine written in PHP, running on nginx and MySQL.

It keeps structure and features apart. The core handles the groundwork — routing, template rendering, migrations, caching, permissions, media handling. Everything beyond writing posts ships as a plugin: friend links, image compression, newsletters, statistics — install what you need and skip the rest. Looks belong to themes. That's why the program itself is small: it ships with one theme (`molin-default`) and one mail plugin (`mail-smtp`).

It fits this situation: you have a domain and a server, you're willing to spend twenty minutes following the install steps, you want a Chinese-friendly interface with decent performance and full ownership of your data, and you don't miss the twenty menus and plugin conflicts that come with heavier systems.

If all you want is a place to write without touching a server, a static site generator (Hugo, Hexo on GitHub Pages) or a hosted platform will serve you better — MolinInk means maintaining the server yourself.

## What it does

**Writing**

- Posts, standalone pages, and content types you define (fields included) for things like a portfolio or notes
- A WYSIWYG editor that doesn't pull in a third-party rich-text library; with JavaScript disabled it falls back to a plain textarea and still saves
- Five post states — draft, pending, private, published, trash; per-post password protection
- Revision history with a diff between saves
- Categories, tags, navigation menus
- Attachment pages: images and files get a public URL

**Running the site**

- Comments with replies, moderation and spam marking; pending items show up on the dashboard
- Media library with automatic multi-size thumbnails, so listings don't load originals
- Search
- `feed` (RSS/Atom), `sitemap.xml`, `robots.txt`
- Users, roles and capabilities: permissions are granted by capability point, not just "admin or editor"
- Backup and restore (whole site packed, downloadable), import/export (`.json`, `.xml`, `.tar.gz`; data only, nothing executed)
- Site health checks (PHP version, required and recommended extensions, PHP settings, writable directories, schema state) and logs

**Extending**

- Themes are Twig templates plus CSS — no PHP required
- Plugins hook into the core and declare the capabilities they use
- REST endpoints under `/api/v1/` for apps or scripts
- A CLI entry point (`php molin`) for backups, migrations and user management in scripts

The interface comes in Simplified Chinese, Traditional Chinese and English, and any single post can be assigned its own language.

## Requirements

| Item | Requirement | Notes |
|---|---|---|
| PHP | 8.2 or newer | 8.3 and 8.4 work |
| Database | MySQL 8.0+ or MariaDB 10.5+ | `utf8mb4` required |
| Web server | nginx (recommended) or Apache | URL rewriting required |
| Disk | 200 MB to start | uploads and backups on top of that |

PHP extensions:

| Extension | Purpose | Without it |
|---|---|---|
| `pdo`, `pdo_mysql` | database access | can't install |
| `mbstring` | multibyte (CJK) strings | can't install |
| `json` | API and cache | can't install |
| `openssl` | encryption and signing | can't install |
| `fileinfo` | real upload type detection | can't install |
| `zip` | installing theme/plugin zips from the admin | that screen reports unavailable |
| `gd` or `imagick` | image thumbnails | originals only, slower listings |
| `intl` | localized date formats | dates fall back to a default format |
| `curl` | uploading backups to object storage | feature unavailable |

These are PHP modules, not Composer packages. On the BT panel: Software Store → PHP 8.x → Settings → Install extension. On the command line, `php -m` lists what's loaded. The installer's first step checks each one and marks what's missing, so you don't have to work it out yourself.

## Install

The steps below assume the BT panel (宝塔); other panels are similar. Twenty minutes if nothing goes wrong. To see the result first: <https://demo.molinink.com>.

### 1. Prepare

Point the domain at the server. The site directory must be writable by the user running PHP (the `www` user by default on BT), because the installer writes `config.php` into the site root and `storage/` holds cache and logs. On other panels, confirm this first — otherwise the installer stalls at the writing step.

### 2. Create the site and database

In the panel: add a site, enter the domain, pick PHP 8.2 or higher, choose MySQL. The panel gives you a database name, user and password — you'll need them in the next step.

### 3. Upload and unzip

Download `molin-<version>.zip` from Releases (not the source archive behind the green `Code` button — see "Which file to download" below). In the site's file manager, open the site root (`/www/wwwroot/your-domain`), upload the zip, extract it there.

You should end up with `index.php`, `core/` and `install/` directly in the root. If everything landed inside an extra folder, go in, select all and move it up.

One thing to avoid: don't point the panel's "run directory" at that subfolder. The rules for `/uploads/` and `/storage/` are written for the site root and quietly stop working if you do.

### 4. Set the rewrite rules

There's one entry point, so the disk has no `/admin` file — nginx has to hand unknown paths to `index.php`, otherwise post pages and the admin both fail. Under Settings → URL rewrite:

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

# Admin is app-routed: admin/ holds only css, js and templates, no index file.
# Without these two lines nginx treats it as a directory and "go to admin" returns 403.
location = /admin {
    try_files $uri /index.php$is_args$args;
}
location = /admin/ {
    return 301 /admin;
}
```

For Apache, the `.htaccess` version is in `install/服务器配置.txt` inside the package.

### 5. Block storage

`storage/` holds backups, logs and remote-backup credentials, so it has to be closed off — otherwise anyone can download it. Under Settings → Config file, inside `server { }`:

```nginx
location ^~ /storage/ {
    return 404;
}
```

Images need nothing extra; the app serves them itself.

### 6. Run the installer

Open your domain in a browser and start: five steps — environment check, database, site and administrator, write, done. A failure rolls everything back, so you never end up half-installed. Delete `install/` when finished.

### 7. Check four URLs

| URL | Expected |
|---|---|
| `your-domain/` | Home page loads |
| `your-domain/admin` | Admin login (404 means the rewrite rules aren't active) |
| `your-domain/storage/backups/` | 404 |
| `your-domain/uploads/some-image` | Image displays |

A longer version with an error-to-cause table ships in `install/安装说明.txt`.

## Which file to download

The installable package is the one in Releases, named `molin-<version>.zip`. It bundles the dependencies (the `vendor/` directory), so you unzip and go.

The green `Code` button gives you the source archive, which has no `vendor/` — dependencies come from Composer and aren't committed. Open that directly and you'll see:

```
require(.../vendor/autoload.php): Failed to open stream
```

Running from source works too: `composer install --no-dev` once in the site root, then the same steps.

One note on versions: what's in this repository isn't necessarily the newest build. I work on my own machine for a while before pushing, and the version published on the website (<https://www.molinink.com>) is the one that actually went out. If they disagree, trust the website.

## First things to do after installing

1. **Settings → Site**: site name, tagline, site URL, timezone, default language. A wrong site URL breaks links the admin generates.
2. **Settings → Comments**: moderation on or off, anonymous comments or not.
3. **Appearance → Themes**: pick a theme and adjust its settings (menus, front-page sections).
4. **Plugins**: activate only what you'll really use. Every extra plugin is another thing that can slow a page down or complicate debugging.
5. **Tools → Backup**: run one backup and download it. One minute here saves a lot later.
6. **Account**: replace the admin password you chose during install with a strong one.

## Upgrading

Tools → Update has a guided upgrade flow with pre-flight checks, but the update source isn't public yet, so checking will report that it can't fetch the manifest — that's expected. For now, upgrade by copying files:

1. Back up first: Tools → Backup, and download a copy.
2. Download the new `molin-<version>.zip` and extract it to a temporary directory on the server.
3. Copy program files over. **Keep** `config.php` and `storage/` (settings, uploads and backups live there). Any theme or plugin you added that isn't in the new package should be moved aside before copying and put back after.
4. If the release changes the database schema, run the migration from the site root:

```bash
php molin db:migrate
```

   A fresh install runs the same migrations, so installing and upgrading share one path and can't drift apart.
5. Clear the cache (empty `storage/cache/`; it rebuilds itself), then walk the four URLs again.

The upgrade doesn't touch `config.php` or `storage/`, but back up anyway — a backup costs a minute, recovering lost data costs more.

## Backup and restore

Tools → Backup packs the database and files into one archive you can download. Restoring happens on the same screen, or from the CLI:

```bash
php molin backup:create     # make a backup
php molin backup:list       # list existing backups
php molin backup:restore    # restore from one
```

Moving a site: take `config.php` with you, export the database, copy `storage/uploads/`, and update the database settings in `config.php`.

## Admin map

| Section | What it's for |
|---|---|
| Dashboard | Site overview, pending comments, recent activity |
| Posts / Pages / Content types | Writing and managing content; revisions live inside a post |
| Categories / Tags | Organising content |
| Media | Uploading, browsing, deleting files |
| Comments | Moderation, replies, spam marking |
| Menus | Front-end navigation |
| Appearance | Themes and theme settings |
| Plugins | Activate, deactivate, install from a zip |
| Users / Roles | Accounts and permissions |
| Tools | Backup, import/export, site health, logs |
| Settings | Site, writing, comments, permalinks, language |
| Update | Version check and upgrade |
| Profile | Your own name, password, preferences |

## Layout

```
.
├── index.php      the single entry point
├── config.php     settings (written by the installer, not in version control)
├── core/          core: routing, templates, database, cache, auth, media, migrations
├── admin/         admin UI (templates + css/js)
├── themes/        one directory per theme
│   └── molin-default/
├── plugins/       one directory per plugin
│   └── mail-smtp/
├── install/       installer; safe to delete once installed
├── storage/       cache, logs, uploads, backups (must stay unreachable from outside)
├── vendor/        dependencies (included in the release package)
└── molin          CLI entry point
```

## Themes and plugins

A theme is a directory: Twig templates, CSS, assets and a `theme.json` manifest. A plugin is a directory plus a manifest declaring which hooks and capabilities it uses. For a minimal working example, read `themes/molin-default` and `plugins/mail-smtp` in this repository — they're shaped exactly like the format expects.

Install ready-made ones by uploading a zip under Appearance → Themes or Plugins. Writing your own: drop the directory in and activate it; template edits take effect on refresh.

Hook names are `domain/object/stage` (for example `post/save/before`) and capabilities are `domain.action` (for example `post.create`). Both namespaces are separate and only ever grow, so plugins don't break when the core is upgraded.

## Running from source

```bash
composer install --no-dev
cp config.example.php config.php   # fill in your database
php molin install                  # or run the web installer
php -S 127.0.0.1:8000              # look at it locally
```

Everything CLI-related hangs off `php molin`; `php molin list` prints the full list.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Blank page or HTTP 500 | PHP errors are hidden | Check `storage/logs/`; confirm PHP ≥ 8.2 and the extensions are present |
| Home page fine, post pages 404 | Rewrite rules missing | Step 4 |
| "Go to admin" returns 403 | The `/admin` blocks are missing | Same step |
| Installer fails at the write step | Site root or `storage/` isn't writable | Give the PHP user ownership or write permission |
| Can't connect to the database | Wrong name/user/password, or the database only accepts local connections | Use the credentials from the panel; host is `127.0.0.1` or `localhost` |
| "No permission to create tables" | The database user is granted only one database | Grant `dbname.*`; wildcard grants (`%`, `_`) are recognised |
| Images don't load | `uploads/` missing or blocked | Check `storage/uploads/` has files and `/uploads/` isn't in a deny rule |
| Large image uploads fail | `upload_max_filesize` / `post_max_size` too small | Raise both and restart PHP |
| Admin looks unstyled | Static assets blocked by a CDN or cache | Clear the cache and confirm `/admin/` serves css |
| Forgot the admin password | — | From the site root: `php molin user:password` |
| Chinese text turns into question marks | Database or connection charset isn't `utf8mb4` | Switch both to `utf8mb4` and re-import |

## Feedback

Stuck during install, or something looks wrong? Ask in the QQ group: 1109527088.

## License

The code is under AGPL-3.0-or-later; the full text is in [LICENSE](LICENSE).

Use it freely, change it however you like, charging for it is fine too. If you modify it and run it as a site for others, publish the part you changed; when you hand a copy to someone, include the LICENSE file.

Per-component licenses for third-party libraries are in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

The 墨林 / MolinInk names and the logo are trademarks and aren't part of the code license. Building your own site with it is fine, and you can change the footer credit — just don't release your own version under the same name and marks.

---

[简体中文](README.md)

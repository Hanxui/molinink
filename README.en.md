<p align="center"><img src=".github/assets/logo.png" alt="MolinInk" width="320"></p>

<p align="center">MolinInk (墨林) is a blog engine written in PHP. Install it and start writing; everything else comes as plugins and themes.</p>

<p align="center"><sub>PHP 8.2+ · MySQL 8 · single entry point · AGPL-3.0</sub></p>

<p align="center"><a href="https://github.com/Hanxui/molinink/releases/latest"><strong>Download</strong></a> · <a href="https://www.molinink.com">Website</a> · <a href="https://demo.molinink.com">Live demo</a></p>

<p align="center"><img src=".github/assets/screen-home.png" alt="Front page"></p>

## Three things that trip people up

**1. Grab the right package.** The one you can actually install is `molin-x.y.z.zip` from Releases. It bundles `vendor/`, so you unzip it into the site root and you're done.

The green `Code → Download ZIP` button gives you the source archive. `vendor/` isn't committed (Composer manages dependencies), so all you'll see is `require(.../vendor/autoload.php): Failed to open stream`, plus an extra `molinink-main/` folder. Running from source works too: run `composer install --no-dev` in the site root once, and the app picks it up from there.

**2. This repo may not be the newest version.** It's a snapshot I sync during development — I usually build, fix and try things locally for a while before pushing. **Treat the version published on the website as the reference: <https://www.molinink.com>.** If the two disagree, trust the website.

**3. Report problems in the group first.** QQ group **1109527088**. That's currently the only feedback channel (Issues works too, but I don't watch it closely; a better route is coming). For security issues, don't post publicly — see [SECURITY.md](SECURITY.md).

## Install

Written for the BT panel (宝塔); other panels are similar. Ten minutes if nothing goes wrong. Want to see it running first: <https://demo.molinink.com>

**Requirements**: PHP 8.2+ (`pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo`; `gd`, `zip`, `intl` recommended), MySQL 8 or MariaDB 10.5+, and a domain already pointing at the server.

### 1. Create the site and database

BT panel → Websites → Add site: enter the domain, pick **PHP 8.2 or higher**, choose MySQL. It hands you a database name, user and password — you'll need them in step 3, so note them down.

### 2. Upload and unzip

Websites → click the site → Files → open the site root (usually `/www/wwwroot/your-domain`) → upload `molin-<version>.zip` from Releases → right-click, extract here.

After extracting, you should see `index.php`, `core/` and `install/` **directly** in the root. If everything landed inside an extra `molin-x.y.z/` folder, go in, select all, and move it up to the root.

One thing to avoid: don't point the panel's "run directory" at that subfolder. Rules for `/uploads/` and `/storage/` are written for the site root and will silently stop working if you do.

### 3. Paste two config blocks

First the rewrite rules (Websites → Settings → **URL rewrite**). There's a single entry point, so the disk has no `/admin` file; nginx has to hand unknown paths to `index.php`:

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

# Admin is app-routed: admin/ only holds static assets (css/js/templates), no index file.
# Without these two lines nginx treats it as a directory index and "go to admin" returns 403.
location = /admin {
    try_files $uri /index.php$is_args$args;
}
location = /admin/ {
    return 301 /admin;
}
```

Without the first block the home page works but `/admin` and every post page returns 404. If you add only the first block and skip the two `/admin` lines, the symptom changes: posts are fine, but logging in returns 403.

Then block `storage/` (Websites → Settings → **Config file**, inside `server { }`). It holds backups, logs and remote backup credentials — left open, anyone can download them:

```nginx
location ^~ /storage/ {
    return 404;
}
```

Images need no extra rule; the app serves them itself. Letting nginx serve `/uploads/` and `/themes/` directly is a bit faster — the snippet is in `install/服务器配置.txt`.

### 4. Run the installer

Open your domain in a browser and click "Start installation": environment check → the database details from step 1 → site name, site URL, admin account → write. A failure rolls the whole thing back, so you never end up with a half-installed site. Delete `install/` when it's done.

### 5. Check four URLs

| Open | Expected |
|---|---|
| `your-domain/` | Home page loads |
| `your-domain/admin` | Admin login (404 = rewrite rules missing) |
| `your-domain/storage/backups/` | 404 |
| `your-domain/uploads/some-image` | Image displays |

The longer version, with an error-to-cause table, is in `install/安装说明.txt` inside the package.

### Running from source instead

```bash
composer install --no-dev
cp config.example.php config.php   # fill in your database
php -S 127.0.0.1:8000              # or hand it to nginx / apache
```

## How it's put together

Short version: the kernel is the socket, contracts are the plug shape.

- No business logic in the kernel. `core/` holds a hook bus, plugin loading, theme rendering, routing, caching and migrations — nothing else.
- Hooks are named `domain/object/stage` (`post/save/before`); capabilities are `domain.action` (`post.create`). Two separate namespaces, additive only.
- Schema changes live in exactly one place: `core/Migrations/`. There is no `schema.sql`; a fresh install simply runs every migration, the same path upgrades take.
- Templates are a restricted Twig subset with auto-escaping on by default; no logic in templates.
- Standard building blocks only: fast-route, Twig, Monolog, symfony/mailer, symfony/console. No ORM, no full-stack framework.

## Admin

<table>
<tr>
<td width="50%"><img src=".github/assets/screen-admin.png" alt="Dashboard"></td>
<td width="50%"><img src=".github/assets/screen-editor.png" alt="Editor"></td>
</tr>
</table>

## License

Code is under **AGPL-3.0-or-later**. Full text: [LICENSE](LICENSE).

- Free to use and modify. Charging for it is fine.
- If you modify it and run it as a site for others, publish your changes.
- Ship the LICENSE file along with any copy you give out.

Per-package licenses for third-party libraries are in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

The 墨林 / MolinInk names and the logo are trademarks and are not covered by the code license. Build your own site with it, change the footer credit if you like — just don't release your own version under the same name and marks.

---

[简体中文](README.md) · QQ group **1109527088** · Security: read [SECURITY.md](SECURITY.md)

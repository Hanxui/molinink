<p align="center"><img src=".github/assets/logo.png" alt="MolinInk" width="320"></p>

<p align="center">A blog engine written in PHP. Install it and start writing.</p>

<p align="center"><sub>PHP 8.2+ · MySQL 8 · single entry point · AGPL-3.0</sub></p>

<p align="center"><a href="https://github.com/Hanxui/molinink/releases/latest">Download</a> · <a href="https://www.molinink.com">Website</a> · <a href="https://demo.molinink.com">Live demo</a></p>

<p align="center"><img src=".github/assets/screen-home.png" alt="Front page"></p>

MolinInk (墨林) is a blog engine written in PHP, running on nginx and MySQL. Once it's installed you can write posts, handle comments and switch themes. The core only covers that groundwork; everything else is a plugin, installed as you need it. A theme and a mail plugin ship with it by default.

## Which file to download

The package you can actually install is the one in Releases, named `molin-<version>.zip`. It bundles the dependencies (the `vendor/` directory), so you unzip it into your site root and you're set.

The green `Code` button on the repository page gives you the source archive, which has no `vendor/` — dependencies come from Composer and aren't committed. Open it directly and all you'll see is:

```
require(.../vendor/autoload.php): Failed to open stream
```

Running from source works fine too: run `composer install --no-dev` once in the site root to fill in the dependencies, then follow the steps below.

One note on versions. What's in this repository isn't necessarily the newest build. I tend to work on my own machine for a while before pushing, and the version published on the website (<https://www.molinink.com>) is the one that actually went out. If the two disagree, trust the website.

## Install

Written for the BT panel (宝塔); other panels are similar. Ten minutes if nothing goes wrong. To see what it looks like first: <https://demo.molinink.com>.

You need PHP 8.2 or newer with `pdo_mysql`, `mbstring`, `json`, `openssl` and `fileinfo` (`gd`, `zip` and `intl` help but aren't required), MySQL 8 or MariaDB 10.5+, and a domain already pointing at the server.

**Create the site and database.** In the BT panel, add a site: enter the domain, pick PHP 8.2 or higher, choose MySQL. The panel gives you a database name, user and password — you'll need them in a moment.

**Upload and unzip.** Open the site's file manager, go to the site root (usually `/www/wwwroot/your-domain`), upload the zip from Releases and extract it there.

Afterwards you should see `index.php`, `core/` and `install/` directly in the root. If everything ended up inside an extra folder, go in, select all and move it up.

One thing to avoid here: don't point the panel's "run directory" at that subfolder. The rules for `/uploads/` and `/storage/` are written for the site root and quietly stop working if you do.

**Set the rewrite rules.** There's a single entry point, so the disk has no `/admin` file — nginx has to hand unknown paths to `index.php`, otherwise post pages and the admin both fail. Put this under Settings → URL rewrite:

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

With only the first block, the home page and posts work, but logging in returns 403.

**Block storage.** `storage/` holds backups, logs and the credentials used for remote backups, so it has to be closed off — otherwise anyone can download it. Under Settings → Config file, inside `server { }`:

```nginx
location ^~ /storage/ {
    return 404;
}
```

Images need nothing extra; the app serves them itself. Letting nginx serve them directly is a bit faster — the snippet is in `install/服务器配置.txt` inside the package.

**Run the installer.** Open your domain in a browser and click to start. First an environment check, then the database details, then the site name, site URL and admin account, then write. A failure rolls everything back, so you never end up with a half-installed site. Delete `install/` when you're done.

**Check four URLs.**

| URL | Expected |
|---|---|
| `your-domain/` | Home page loads |
| `your-domain/admin` | Admin login (404 means the rewrite rules aren't active) |
| `your-domain/storage/backups/` | 404 |
| `your-domain/uploads/some-image` | Image displays |

A longer version with an error-to-cause table is in `install/安装说明.txt` inside the package.

Running from source instead:

```bash
composer install --no-dev
cp config.example.php config.php   # fill in your database
php -S 127.0.0.1:8000              # or hand it to nginx / apache
```

## How the code is organised

The core handles structure only — no looks, no features. `core/` contains the hook bus, plugin loading, theme rendering, routing, caching and migrations.

Plugins and themes talk to the core through contracts. Hooks are named `domain/object/stage`, like `post/save/before`; capabilities are `domain.action`, like `post.create`. The two namespaces are separate and only ever grow — existing names don't change.

Schema changes have exactly one home: `core/Migrations/`. There's no `schema.sql`; a fresh install simply runs every migration from an empty database, the same path an upgrade takes, so the two can't drift apart.

Templates are a restricted Twig subset with auto-escaping on by default, and they only render — no business logic.

Routing, templating, logging and mail come from existing components: fast-route, Twig, Monolog, symfony/mailer, symfony/console. No ORM, no full-stack framework.

## Admin

<table>
<tr>
<td width="50%"><img src=".github/assets/screen-admin.png" alt="Dashboard"></td>
<td width="50%"><img src=".github/assets/screen-editor.png" alt="Editor"></td>
</tr>
</table>

## License

The code is under AGPL-3.0-or-later; the full text is in [LICENSE](LICENSE).

Use it freely, change it however you like, charging for it is fine too. If you modify it and run it as a site for others, publish the part you changed; when you hand a copy to someone, include the LICENSE file.

Per-component licenses for third-party libraries are in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

The 墨林 / MolinInk names and the logo are trademarks and aren't part of the code license. Building your own site with it is fine, and you can change the footer credit — just don't release your own version under the same name and marks.

---

[简体中文](README.md) · QQ group 1109527088

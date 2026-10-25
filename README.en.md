<p align="center"><img src=".github/assets/logo.png" alt="MolinInk" width="320"></p>

<p align="center">A small, contract-driven blogging engine. The kernel provides structure only — features arrive as plugins, appearance as themes.</p>

<p align="center"><sub>PHP 8.2+ · MySQL 8 · single entry point · write a post right after installing</sub></p>

<p align="center"><a href="https://www.molinink.com">Website</a> · <a href="https://demo.molinink.com">Live demo</a></p>

<p align="center"><img src=".github/assets/screen-home.png" alt="Front page"></p>

## Install

Written for the BT panel (宝塔); other panels work the same way. About ten minutes. Want to see it running first: <https://demo.molinink.com>

**Requirements**: PHP 8.2+ (`pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo`; `gd`, `zip`, `intl` recommended), MySQL 8 or MariaDB 10.5+, and a domain already pointing at the server.

**1. Create site and database**
BT panel → Website → Add site: enter your domain, pick **PHP 8.2 or newer**, choose MySQL as the database. Note the **database / user / password** it generates — you need them in step 3.

**2. Upload and unzip**
Website → your site → Files → open the site root (usually `/www/wwwroot/yourdomain`) → upload `molin-<version>.zip` → right-click → extract here.
You should see `index.php`, `core/` and `install/` **directly** in the root. If everything landed inside a `molin-x.y.z/` folder, move its contents up to the root.

**3. Paste two snippets**

Pretty URLs (Website → Settings → **Rewrite**) — so `/admin` and post URLs resolve. The program has a single entry point; there is no real `/admin` file on disk, so nginx has to hand unknown paths to `index.php`:

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

Without it: the front page works, `/admin` and every post URL return 404.

Block `storage/` (Website → Settings → **Config**, inside `server { }`) — backups, logs and remote-backup credentials live there and are downloadable otherwise:

```nginx
location ^~ /storage/ {
    return 404;
}
```

Images need no config — the program serves them. (`/uploads/` and `/themes/` snippets in `install/服务器配置.txt` are optional speedups.)

**4. Run the installer**
Open your domain → "Start install": environment check → database credentials from step 1 → site name, site URL, admin account → write (rolls back on failure). Then delete `install/`.

**5. Check four URLs**

| Open | Expected |
|---|---|
| `yourdomain/` | front page loads |
| `yourdomain/admin` | admin login (404 = rewrite rule missing) |
| `yourdomain/storage/backups/` | 404 |
| `yourdomain/uploads/some-image` | image displays |

The long version (with a troubleshooting table) ships in `install/安装说明.txt` inside the zip.

Or run it from source:

```bash
composer install
cp config.example.php config.php   # fill in your database
php -S 127.0.0.1:8000              # or hand it to nginx / apache
```

## Design

**The kernel is a socket; contracts are the plug specs.**

- No business logic in the kernel. `core/` holds a hook bus, plugin loading, theme rendering, routing, caching and migrations — nothing else.
- Hooks are named `domain/object/stage` (`post/save/before`); capabilities are `domain.action` (`post.create`). Two separate namespaces, additive only.
- Schema changes live in exactly one place: `core/Migrations/`. There is no `schema.sql`.
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

- Free to use and modify. Charging money is fine.
- If you modify it and run it as a site for others, publish your changes.
- Ship the LICENSE file along with any copy you give out.

Per-package licenses for third-party libraries are in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

The 墨林 / MolinInk names and the logo are trademarks and are not covered by the code license. Build your own site with it, change the footer credit if you like — just don't release your own version under the same name and marks.

---

[简体中文](README.md) · QQ group: **1109527088** · Questions and bugs: [Issues](../../issues) · Security: read [SECURITY.md](SECURITY.md) first
# TCT Website Plugins

Custom WordPress plugins for [teecorleytravels.com](https://teecorleytravels.com).

| Plugin | Purpose |
|---|---|
| [`tct-reader-kit`](tct-reader-kit/) | Sidebar table of contents + newsletter signup with subscriber storage |

## Building an installable zip

```bash
cd tct-website-plugins
zip -rq ../tct-reader-kit.zip tct-reader-kit -x '*.DS_Store'
```

Then upload via **Plugins → Add New → Upload Plugin** in wp-admin.

## Before shipping a change

Requires PHP (`brew install php`):

```bash
for f in $(find tct-reader-kit -name '*.php'); do php -l "$f"; done
```

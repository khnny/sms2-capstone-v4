# Bestlink SMS2 landing page

The page at https://bestlinksms2portal.com. It links to every SMS2 subsystem.

## Add or change a subsystem link

Edit `links.js`. Copy one `{ ... }` block, change `name`, `description`, `url` and `icon`, and push. HostForge redeploys it.
Use `status: "soon"` for a subsystem that is not live yet (shown greyed out).

## Deploy on HostForge

New application from this repo. It builds from the `Dockerfile` (nginx, listens on `$PORT`, default 8000). No database or environment variables.
Then in the app's **Domains** tab, click **Use this domain** on `bestlinksms2portal.com`.

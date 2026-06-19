# Exposing the test app with Expose (via Docker)

Some Shipmondo features can only be tested against a **publicly reachable** URL. In particular,
when you register webhooks (`bin/console setono:sylius-shipmondo:register-webhooks` or the
*Register webhooks* button on the admin Shipmondo page), Shipmondo calls each webhook endpoint
**immediately at creation time and expects an HTTP 200** — so a `localhost` URL is rejected.

[Expose](https://expose.dev) creates a public HTTPS tunnel to your local server. This directory
contains a small, self-contained Docker image for the Expose **client** so you don't need PHP/Expose
installed on the host. (`beyondcode/expose` is already a `require-dev` dependency of the plugin; this
image just makes it runnable via Docker, matching the "Via Docker" flow in the Expose docs.)

## Prerequisites

- Docker running.
- The test app served locally, e.g. `symfony serve -d` (defaults used below assume it listens on
  `http://127.0.0.1:8123`).
- A free Expose auth token from https://expose.dev (Settings → token).

## 1. Build the image (once)

```bash
docker build -t setono-shipmondo-expose tests/Application/docker/expose
```

## 2. Activate your token + free server (once)

The token is stored in `~/.expose` on the host via a mounted volume, so it persists between runs.

```bash
docker run --rm -v "$HOME/.expose:/root/.expose" setono-shipmondo-expose token <YOUR_TOKEN>
docker run --rm -v "$HOME/.expose:/root/.expose" setono-shipmondo-expose default-server free
```

## 3. Share the local server

`host.docker.internal` lets the container reach the server running on your host.

```bash
docker run --rm -it \
  -v "$HOME/.expose:/root/.expose" \
  --add-host host.docker.internal:host-gateway \
  setono-shipmondo-expose share http://host.docker.internal:8123
```

Expose prints a public URL like `https://<random>.sharedwithexpose.com`. Keep this running.

## 4. Register webhooks against the public URL

The webhook URLs are generated from the **current request context**, so register them while
browsing the admin through the public URL (the generated endpoints then point at the tunnel):

1. Open `https://<random>.sharedwithexpose.com/admin/shipmondo` and log in.
2. Click **Register webhooks**.

Alternatively, run the CLI command with the router default URI pointed at the tunnel:

```bash
(cd tests/Application && \
  SYMFONY_DEFAULT_ROUTE_HOST=<random>.sharedwithexpose.com \
  bin/console setono:sylius-shipmondo:register-webhooks)
```

(For the CLI route to emit `https://` absolute URLs you may also need
`framework.router.default_uri` configured; browsing via the public URL in step 4 avoids that.)

You can watch incoming webhook traffic in Expose's local dashboard at http://127.0.0.1:4040.

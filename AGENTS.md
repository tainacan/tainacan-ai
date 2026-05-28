# Agent notes (maintainers)

This file is for AI assistants and maintainers using the **tainacan-docker** dev stack. It is not required reading for general plugin development.

## Docker build environment

When the workspace is mounted in `tainacan-docker`, run PHP/Composer/npm commands inside the build container:

```bash
docker exec -it tainacan_build bash
cd /src/tainacan-ai
```

Then run `composer install`, `npm install`, `npm run build`, `composer phpcs`, etc. from that path.

Do not assume `/var/www/html/...` unless that path exists in the container; prefer `/src/tainacan-ai`.

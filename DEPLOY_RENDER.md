# Render deployment notes

This project is a Laravel 12 API. On Render, PHP is deployed through Docker rather than a native PHP runtime, so this repository now includes a `Dockerfile` and `render.yaml`.

## Recommended service layout

Create these Render services from `render.yaml`:

- `bazario-api` web service
- `bazario-queue` background worker

The queue worker is required because Stripe webhook processing is queued in `app/Jobs/ProcessStripeEventJob.php`.

## Database

This app already supports MySQL in `config/database.php`. Render does not expose a managed MySQL product in the docs we checked, so use one of these approaches:

1. Preferred: connect Render to an external managed MySQL database.
2. Possible but higher-maintenance: run your own MySQL on Render with a persistent disk.

If you use external MySQL, set these env vars in both services:

- `DB_CONNECTION=mysql`
- `DB_HOST`
- `DB_PORT=3306`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

## File uploads

The app stores uploaded files on Laravel's local `public` disk under `storage/app/public`. Render filesystems are ephemeral unless you attach a persistent disk, so `bazario-api` mounts one at `/var/www/html/storage`.

If you later move uploads to S3-compatible storage, you can remove the disk and switch the filesystem config.

## Environment values to set

Set these in Render:

- `APP_KEY` as a Laravel app key such as `base64:...`
- `APP_URL`
- `CORS_ALLOWED_ORIGINS`
- `SANCTUM_STATEFUL_DOMAINS` if you later switch to cookie-based SPA auth
- Stripe variables used by checkout/connect flows

Suggested `CORS_ALLOWED_ORIGINS` value while developing locally:

```text
http://localhost:5173,http://127.0.0.1:5173,http://localhost:3000,http://127.0.0.1:3000,https://your-react-app.example
```

## Local React consumption

Your login/register endpoints return Sanctum bearer tokens, so a local React app can consume this API directly with:

- `Authorization: Bearer <token>`
- one of the allowed origins listed in `CORS_ALLOWED_ORIGINS`

Cookie-based Sanctum is not required for the current auth flow.

## Deploy checklist

1. Push this repository with the new Docker/Render files.
2. Create the web service and worker from `render.yaml`.
3. Generate an app key with `php artisan key:generate --show` and set it as `APP_KEY`.
4. Fill in the remaining required environment variables.
5. Point the database env vars to your MySQL instance.
6. Confirm the web service passes `/up`.
7. Test login, file upload, and a Stripe webhook path.

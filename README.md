# Photographer product review

Small PHP/JS application for reviewing the current `arts/arts.txt` list.

## Run locally with Docker

```sh
docker compose up --build
```

Open `http://localhost:8080`. The default Docker environment uses local demo product data and the `no-photo.jpg` placeholder. The project root is mounted into the container, so changes to `arts/arts.txt` and generated files persist on the host.

To run the focused PHP tests in the same container image:

```sh
docker compose run --rm photographer php tests/run.php
```

## Behavior

- Select a warehouse to see all of its products as cards on one page. Items without a product image stay in their warehouse and use the placeholder image.
- Items in the `Не найдено в базе` section can be exported separately, removed individually, or removed together after confirmation.
- Deleting a list entry changes only `arts/arts.txt`; it never deletes a Bitrix product.
- The photographer can export one selected warehouse or all warehouses at once; `not-found.txt` is a separate export. Files are created only after an explicit button click and stored in `exports/`.
- `arts.txt` is replaced atomically after checking that its contents have not changed since the form was loaded.

## Bitrix integration

When `/bitrix/modules/main/include/prolog_before.php` exists at the project root, the app uses the Bitrix catalog and file APIs. An alternate prolog path can be supplied through `BITRIX_PROLOG_PATH`. Product lookups use catalog property ID 59 (`CML2_ARTICLE`), product names, and detail images resized through `CFile::ResizeImageGet` to 300x300.

In a Bitrix environment, only authorized users with IDs 1 and 357 can access the page unless `APP_ENV=local`. Without Bitrix, the app runs with local demo data. All state-changing forms require a CSRF token.

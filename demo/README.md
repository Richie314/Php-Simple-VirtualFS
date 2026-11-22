# Simple VirtualFS demo

The Docker Compose stack runs a PHP 8.3 web app and a persistent MinIO bucket.
Google Drive uses the live API from the same PHP container.
Both backends appear in the dashboard and the integration check.

## Run the demo

1. From the checkout, run `cd demo` and `cp .env.example .env`.
2. Create a dedicated Drive folder named **VirtualFS Demo**.
   Fill in the Google OAuth client ID, secret, and refresh token using the [Google Drive setup instructions](../README.md#google-drive).
   For a different folder, set `GOOGLE_DRIVE_FOLDER` to its Drive-relative path, or set `GOOGLE_DRIVE_FOLDER_ID` to the folder ID from its URL.
   The account needs permission to create, read and delete files in that folder.
3. Run `docker compose up --build -d` and open [localhost:8080](http://localhost:8080).
   Set `DEMO_PORT` in `.env` if the port is in use.

MinIO and its demo bucket are provisioned automatically.
If the OAuth values are empty, the Google Drive card shows that credentials are required and the S3 tests remain available.
The demo uses local-only MinIO credentials and publishes its web port on loopback.

## Test both backends

Use **Run round-trip test** to write a sample, fetch its metadata, verify its contents and delete it.
**Test an upload** exercises PHP multipart upload handling with the selected backend and verifies the returned bytes before removing the test file from the folder.
Google Drive sends test files to Trash.

Use **Browse virtual folders** to inspect a mount root or a folder within it.
Select **Include subfolders** for a recursive listing.

Run these commands from `demo/` while the stack is running.

```sh
docker compose exec app php demo/check.php
docker compose exec app composer test
```

The first command checks both remote mounts and exits nonzero if either check fails or Drive credentials are missing.
The second runs the library smoke tests against local storage.

Run `docker compose down` to stop the demo.
The MinIO volume persists between runs.

# Database dump for Docker

Put the KonektaDOST database dump here, for example `konektadost.sql` (or `konektadost.sql.gz`).

On the **first** `docker compose up`, the database container imports every `*.sql` / `*.sql.gz` file in this
folder into the `konektadost` database. Later starts reuse the stored data and ignore this folder.

- Get the dump from the team's backup folder, or export one from a working XAMPP install (see README, section 1.4).
- To import again from scratch: `docker compose down -v` (deletes the Docker database), then `docker compose up -d`.
- Dumps are large and private: this folder's `*.sql*` files are git-ignored.

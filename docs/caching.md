# Read caching

Caching is enabled by default outside automated tests. It uses Laravel's file cache, so no additional database tables or services are required.

- Map inventories, agriculture, catalogs, assessments and deterministic function results: 5 minutes.
- Geographic lists and boundaries: 1 hour.
- Planning histories and saved plans: 30 seconds, isolated by user and location.
- Authentication, account settings, writes, session data and complete HTTP responses are not cached. AI analysis keeps its existing evidence-based cache.

Successful Laravel database writes invalidate the affected caches, including bulk/query-builder updates. Transactional changes invalidate after commit; uncommitted reads bypass caching.

After importing or editing data directly in MySQL, run:

```bash
php artisan read-cache:clear
```

Configure `READ_CACHE_ENABLED`, `READ_CACHE_STORE`, `READ_CACHE_TTL`, `READ_CACHE_GEOGRAPHY_TTL` and `READ_CACHE_PLANNING_TTL` in `.env`. Set `READ_CACHE_ENABLED=false` to disable read caching. After changing these settings, run `php artisan config:clear` (or rebuild the configuration cache during deployment).

After deploying changes to calculation formulas, run `php artisan read-cache:clear` or change `READ_CACHE_VERSION` to stop reusing results computed by the previous code.

# KonektaDOST

**Geospatial decision support for the Department of Science and Technology (DOST), Philippines.**

KonektaDOST answers one question: _for a selected province, city/municipality or barangay, which DOST technologies and STI programs should be deployed there, why, and how?_ It measures local conditions, compares them with national reference rates, finds development gaps, ranks target areas, matches the gaps against the DOST catalog and drafts an action plan with five executive outputs:

1. Priority Areas
2. Critical Needs and Development Gaps
3. Recommended STI Interventions
4. Investment and Resource Priorities
5. Expected Outcomes and Performance

All numbers come from deterministic analysis tools. The AI assistant, **GeoAgent**, only explains, orchestrates and drafts. Missing data is never treated as zero, and every recommendation is a draft until the LGU and DOST validate it.

**Contents:** [Installation](#1-installation-guide) · [Running on another device](#17-running-on-another-device) · [Troubleshooting](#18-troubleshooting) · [Docker](#19-run-with-docker) · [Project structure](#2-project-structure) · [Project usage](#3-project-usage) · [User manual](#4-user-manual) · [More documentation](#5-more-documentation)

---

## 1. Installation guide

### 1.1 Requirements

| Software         | Version                                                   | Notes                                                                                                                                  |
| ---------------- | --------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| PHP              | 8.2 or newer (tested on 8.5)                              | Extensions: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`, `gd`, `xml`. `composer install` lists any that are missing. |
| Composer         | 2.x                                                       |                                                                                                                                        |
| Node.js and npm  | Node 20 or newer (tested on 24)                           | Used to build the React frontend.                                                                                                      |
| MySQL or MariaDB | MySQL 8 or MariaDB 10.4+ (tested on XAMPP's MariaDB 10.4) | The database is about 280 MB.                                                                                                          |
| Internet access  |                                                           | For the AI gateway (GATES) and map tiles.                                                                                              |

On Windows the simplest setup is **XAMPP** (PHP, MariaDB and Apache in one installer) plus Composer, Node.js and Git.

### 1.2 Get the code and install dependencies

```bash
git clone <repository-url> konekta-dost
cd konekta-dost-eshen
composer install
npm install
```

### 1.3 Create the environment file

```bash
copy .env.example .env        # macOS/Linux: cp .env.example .env
php artisan key:generate
```

Open `.env` and set at least:

```dotenv
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=konektadost
DB_USERNAME=root
DB_PASSWORD=
```

The AI settings are covered in [1.5](#15-configure-the-ai-gates).

### 1.4 Set up the database

The geography, population, facility, laboratory, telecom, road, agriculture and DOST catalog tables are **imported data, not migrations**. A fresh `php artisan migrate` on an empty database does **not** create them. Some early migrations also create tables that the data already contains, so always start from a **complete copy of a working database**.

**Step 1: Get a database copy.** Either download the backup from the team's Google Drive folder:

<https://drive.google.com/drive/folders/1ojUeuHnRfWpIANlDNy0v5-_APo4pVScg?usp=sharing>

or export the current database from a working PC (XAMPP paths shown):

```bat
"C:\xampp\mysql\bin\mysqldump.exe" -u root --single-transaction --routines --default-character-set=utf8mb4 --result-file=konektadost.sql konektadost
```

`--result-file` matters on Windows: redirecting with `>` in PowerShell writes UTF-16 and corrupts the dump.

**Step 2: Allow large rows.** Boundary polygons make some rows several megabytes. In XAMPP open `C:\xampp\mysql\bin\my.ini`, set the following under `[mysqld]`, then restart MySQL from the XAMPP Control Panel:

```ini
max_allowed_packet=512M
```

**Step 3: Create the database and import.** This works in any terminal:

```bat
"C:\xampp\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE konektadost CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
"C:\xampp\mysql\bin\mysql.exe" -u root --max_allowed_packet=512M konektadost -e "source C:/path/to/konektadost.sql"
```

**Step 4: Apply pending migrations and refresh the read cache:**

```bash
php artisan migrate          # runs only migrations newer than the copy
php artisan read-cache:clear
```

Never run `migrate:fresh`, `migrate:reset` or `migrate:refresh` on `konektadost`: they drop the imported data.

**Step 5: Accounts.** A copied database keeps its user accounts. For an empty `users` table only, create the team's two admin and two user accounts listed in `database/seeders/UserSeeder.php`, then change their passwords after signing in:

```bash
php artisan db:seed --class=UserSeeder
```

Anyone can also register at `/register` (role `user`); an admin can change roles in **Admin › Users**.

**Step 6: Older backups only.** If assessments show almost no facilities or laboratories for large cities, rebuild the resource location links from coordinates:

```bash
php artisan resources:relink-locations          # dry run: shows what would change
php artisan resources:relink-locations --apply  # writes a backup CSV to storage/app/diagnostics first
```

### 1.5 Configure the AI (GATES)

There is **no separate AI server to install**. GeoAgent and the assessment AI call the **GATES LLM gateway**, a cloud service, over HTTPS from the Laravel server. Each machine that runs KonektaDOST needs internet access and these `.env` values:

```dotenv
GATES_API_KEY=gates_your_key_here        # created in the GATES dashboard
GATES_BASE_URL=https://ga-40e2b0fc69114ba88bbb8d1cc6ec4d42.ecs.us-east-1.on.aws
GATES_MODEL=openai.gpt-6-luna
```

- `GATES_BASE_URL` is the gateway origin from the GATES dashboard, **without** `/v1`. A key works only with the gateway of the environment it was created in.
- Once `GATES_API_KEY` is set, GATES is used automatically. Without it the app falls back to OpenAI (`OPENAI_API_KEY`, `OPENAI_MODEL`). `AI_PROVIDER=gates` or `AI_PROVIDER=openai` forces one.
- Optional: `GATES_TIMEOUT` (default 60 s per call), `GATES_REASONING_EFFORT` (default `low`), `GATES_CA_BUNDLE` (see below).
- Keep the key out of Git, screenshots and any `VITE_` variable. Rotate it in the GATES dashboard if it leaks.

**HTTPS certificates on Windows.** PHP must be able to verify the gateway's certificate. XAMPP normally sets `curl.cainfo` in `php.ini`. If the AI reports a certificate or connection error, download <https://curl.se/ca/cacert.pem> and point to it with an **absolute path on this machine**:

```dotenv
GATES_CA_BUNDLE=C:/certs/cacert.pem
```

`GATES_CA_BUNDLE` falls back to `OPENAI_CA_BUNDLE`. If you copied `.env` from another PC, clear or fix any `OPENAI_CA_BUNDLE` path that does not exist here, or every AI call will fail.

**Verify:**

```bash
php artisan config:clear
php artisan ai:check            # free: checks URL, key and model, no credit used
php artisan ai:check --tools    # tiny paid check: one tool round trip, like a GeoAgent turn
```

If AI is not configured, everything else keeps working. Assessments, maps and the computed action plan do not need it.

### 1.6 Run the application

**Development on one PC** (web server, queue worker and Vite with hot reload):

```bash
composer dev
```

Open <http://127.0.0.1:8000>.

**For everyday use, or for other devices,** build the frontend once instead of running Vite:

```bash
npm run build
```

Then delete `public/hot` if it exists. `npm run dev` creates it, and while it exists pages load scripts from the Vite dev server, which other devices cannot reach. Rebuild after every frontend change you pull.

### 1.7 Running on another device

There are two situations.

**A. Install KonektaDOST on another computer.** Follow 1.1 to 1.5 on that computer, using the same database copy, and check:

1. `.env` was created for this machine (`php artisan key:generate`, correct `DB_*`), not copied with paths from another PC.
2. The `GATES_*` values are set and `php artisan ai:check` passes.
3. `npm run build` was run and `public/hot` does not exist.

**B. Open KonektaDOST from phones or laptops on the same network.** Run it on one PC (the server) and let others connect:

1. Find the server's IP address: run `ipconfig` and note the IPv4 Address, e.g. `192.168.1.20`.
2. Set `APP_URL=http://192.168.1.20:8000` in `.env`, then run `php artisan config:clear`.
3. Build the frontend (`npm run build`) and delete `public/hot`.
4. Start the server so it listens on the network:

    ```bash
    php artisan serve --host=0.0.0.0 --port=8000
    ```

5. Allow the port through Windows Firewall once, in PowerShell as Administrator:

    ```powershell
    New-NetFirewallRule -DisplayName "KonektaDOST 8000" -Direction Inbound -Protocol TCP -LocalPort 8000 -Action Allow
    ```

6. On the other devices open `http://192.168.1.20:8000`.

**For several users at once, use Apache.** `php artisan serve` runs one PHP process, and a GeoAgent reply can take up to 2½ minutes, so other users wait meanwhile. Apache in XAMPP serves many requests in parallel:

1. Add a virtual host to `C:\xampp\apache\conf\extra\httpd-vhosts.conf` (adjust the path):

    ```apache
    <VirtualHost *:80>
        DocumentRoot "C:/path/to/konekta-dost-eshen/public"
        <Directory "C:/path/to/konekta-dost-eshen/public">
            AllowOverride All
            Require all granted
        </Directory>
    </VirtualHost>
    ```

2. In `C:\xampp\php\php.ini` set `max_execution_time = 180`, so a long GeoAgent reply is not cut off.
3. Set `APP_URL=http://192.168.1.20`, `APP_ENV=production` and `APP_DEBUG=false`, then run `php artisan config:clear`.
4. Restart Apache from the XAMPP Control Panel, allow port 80 through the firewall, and open `http://192.168.1.20`.

### 1.8 Troubleshooting

| Symptom                                                 | Fix                                                                                              |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `Table ... already exists` during `php artisan migrate` | The database is not a complete copy. Import a full dump (1.4), including its `migrations` table. |
| `MySQL server has gone away` during import              | Raise `max_allowed_packet` (1.4, Step 2) and restart MySQL.                                      |
| Other devices see a blank page or missing styles        | Run `npm run build` and delete `public/hot`.                                                     |
| `Vite manifest not found`                               | Run `npm run build`.                                                                             |
| GeoAgent says "The AI assistant is not configured"      | Set `GATES_API_KEY` and `GATES_BASE_URL`, then `php artisan config:clear`.                       |
| "The AI gateway rejected the server key"                | Use a key created for the same gateway as `GATES_BASE_URL`.                                      |
| "The AI gateway has no credit available"                | Check the balance on the GATES dashboard.                                                        |
| "The configured AI model is not enabled"                | Run `php artisan ai:check` and set `GATES_MODEL` to a listed model.                              |
| AI fails with `cURL error 60` or `77` (certificates)    | Set `GATES_CA_BUNDLE` to a valid absolute path on this machine (1.5).                            |
| GeoAgent stops after about 2 minutes                    | Raise `max_execution_time` in `php.ini` to 180.                                                  |
| Assessments show almost no facilities or laboratories   | Run `php artisan resources:relink-locations --apply` (1.4, Step 6).                              |
| Data edited directly in MySQL does not appear           | Run `php artisan read-cache:clear`.                                                              |
| "Your session has expired"                              | Refresh the page; sign in again if asked.                                                        |

### 1.9 Run with Docker

Docker is an alternative to XAMPP. It builds everything itself (PHP 8.4 with Apache, the Composer packages and the frontend) and runs its own MariaDB database. You need only Docker Desktop and the database dump.

1. Install [Docker Desktop](https://www.docker.com/products/docker-desktop/).
2. Put the database dump in `docker/mysql-init/`, e.g. `docker/mysql-init/konektadost.sql` ([1.4](#14-set-up-the-database) explains how to get one). It is imported on the **first** start only.
3. Make sure `.env` has `APP_KEY` (`php artisan key:generate --show` prints a new one) and the `GATES_*` settings. The containers read the rest of `.env`, but `docker-compose.yml` sets its own database host, credentials and URL, and ignores local certificate paths.
4. Build and start:

   ```bash
   docker compose up -d --build
   ```

5. The first start imports the dump, which takes several minutes; follow it with `docker compose logs -f db`. The app starts once the import has finished. Open <http://localhost:8081>; from other devices use `http://<this-PC-ip>:8081`, and set `DOCKER_APP_URL` in `.env` to that address.

| Task | Command |
| --- | --- |
| Start, or rebuild after code changes | `docker compose up -d --build` |
| Stop | `docker compose down` |
| Follow the app's logs | `docker compose logs -f app` |
| Run an artisan command | `docker compose exec -u www-data app php artisan ai:check` |
| Refresh computed data | `docker compose exec -u www-data app php artisan read-cache:clear` |
| Re-import the database from scratch (deletes the Docker database) | `docker compose down -v`, then `docker compose up -d` |

Always run artisan with `-u www-data`. Commands run as root create cache files the web server cannot write to later.

Optional `.env` settings for Docker:

| Setting | Default | Meaning |
| --- | --- | --- |
| `DOCKER_APP_PORT` | `8081` | Port the app is published on. |
| `DOCKER_APP_URL` | `http://localhost:8081` | Address users open; set it to `http://<this-PC-ip>:8081` for other devices. |
| `DOCKER_APP_DEBUG` | `false` | Show detailed error pages (testing only). |
| `DOCKER_DB_PASSWORD` / `DOCKER_DB_ROOT_PASSWORD` | `konektadost_secret` / `root_secret` | Database passwords. Change them before the first start. |
| `DOCKER_DB_PORT` | `3307` | Database port for tools on this PC only (`127.0.0.1`). |

On start the container applies pending migrations only if the imported data is present. On an empty database it skips them, so a later import is not blocked.

#### Registry (GitLab CI and Harbor)

`.gitlab-ci.yml` builds the image on every push to the default branch and pushes it to Harbor as `$IMAGE:<commit>` and `$IMAGE:latest`. Set these in **GitLab › Settings › CI/CD › Variables**:

| Variable | Example | Notes |
| --- | --- | --- |
| `REGISTRY` | `harbor.example.org` | Harbor host. |
| `IMAGE` | `harbor.example.org/<project>/konektadost` | Full image path, without a tag. |
| `HARBOR_ROBOT_NAME` | `robot$konektadost+ci` | Turn off **Expand variable reference**: the name contains `$`. |
| `HARBOR_ROBOT_SECRET` | | Mask and protect it. |

The job uses Docker-in-Docker, which needs a privileged runner. With a shell runner, or one that mounts the host's Docker socket, remove `image`, `services` and `variables` from the job.

The image contains no `.env`, API keys or database, so it is safe to store in the registry. To run it on a server **without the source code**:

1. Copy `docker-compose.yml`, the `docker/mysql-init/` folder (with the dump) and a `.env` for that server.
2. In `.env`, set `KONEKTADOST_IMAGE=<registry>/<project>/konektadost:latest` (or a commit tag), plus `APP_KEY`, the `GATES_*` settings and `DOCKER_APP_URL`.
3. Pull and start:

   ```bash
   docker login <registry>
   docker compose pull app
   docker compose up -d --no-build
   ```

To update, run `docker compose pull app && docker compose up -d --no-build` again.

---

## 2. Project structure

```text
konekta-dost-eshen/
├─ app/
│  ├─ Console/Commands/      ai:check, resources:relink-locations, app:dost-project-query
│  ├─ FunctionTools/         the 33 deterministic analysis tools (Layers A–F) and their registry
│  │  ├─ LayerA … LayerF/    data retrieval, sector analysis, gaps and priorities, catalog, matching, planning
│  │  └─ Support/            location resolver and spatial helpers
│  ├─ Http/Controllers/      LocationController (assessments), PlanningChatController (GeoAgent),
│  │                         AssessmentActionPlanController (saved plans), UserDashboardController, Admin/*
│  ├─ Models/                Province, Municipality, Barangay, Facility, Laboratory, TelecomSite, DostTech, …
│  └─ Support/
│     ├─ Ai/                 ResponsesClient (GATES or OpenAI), PlanningChatService (GeoAgent), chat history
│     ├─ Assessment/         indicators, reference rates, gap diagnostics, AI explanation, saved action plans
│     ├─ Map/                geography explorer, map markers, roads, province resources
│     ├─ Caching/            ReadCache: computed results cached and refreshed after database writes
│     ├─ DataEntries/        admin data-entry definitions (one per sector)
│     └─ Dashboard/          user and admin dashboard data
├─ config/services.php       AI provider settings (ai, gates, openai)
├─ docker/                   PHP and Apache settings, container start-up script, mysql-init/ (dump to import)
├─ Dockerfile                image build: PHP 8.4 + Apache, Composer packages, frontend build
├─ docker-compose.yml        app and MariaDB containers
├─ .gitlab-ci.yml            builds the image and pushes it to the Harbor registry
├─ database/
│  ├─ migrations/            application tables only: users, sessions, cache, GeoAgent history, saved plans, indexes
│  ├─ seeders/UserSeeder.php team accounts
│  └─ schema/                SQL for tables delivered outside migrations (telecom sites)
├─ docs/                     feature notes (planning chat, map explorer, caching, reports)
├─ gates_llm-0.2.0/          GATES Python SDK, kept as reference only (the app calls the gateway's HTTP API)
├─ resources/
│  ├─ js/pages/              Inertia pages: welcome, auth, users/dashboard, users/intellegence-map,
│  │                         location-assessment (+ its steps, action plan and saved-plan views), admin/*
│  ├─ js/components/         planning-chat (GeoAgent), philippine-map, assessment-method charts, ui/
│  ├─ js/lib/                report and action-plan builders, PDF generators, CSRF helper
│  ├─ css/app.css            Tailwind styles and chart palette
│  ├─ fonts/noto-sans/       fonts embedded in the PDF exports
│  └─ images/                DOST logo
├─ routes/web.php            all web and JSON routes
├─ tests/Feature/            PHP feature tests (most use an isolated in-memory database)
└─ tests/Frontend/           Node tests for the report, action-plan, PDF and CSRF logic
```

**Stack:** Laravel 12, PHP 8.2+, Inertia 2, React 19, TypeScript, Vite 6, Tailwind 4, MapLibre GL and Leaflet (maps), jsPDF (PDF exports), MySQL/MariaDB, and the GATES gateway (OpenAI-compatible Responses API) for AI.

---

## 3. Project usage

Everyday commands, run from the project folder:

| Task                                                  | Command                                                         |
| ----------------------------------------------------- | --------------------------------------------------------------- |
| Start the development servers                         | `composer dev`                                                  |
| Build the frontend for use                            | `npm run build`                                                 |
| Serve on the network                                  | `php artisan serve --host=0.0.0.0 --port=8000`                  |
| Check the AI setup (free)                             | `php artisan ai:check`                                          |
| Test a real AI tool round trip (tiny cost)            | `php artisan ai:check --tools`                                  |
| Refresh computed results after editing data in MySQL  | `php artisan read-cache:clear`                                  |
| Rebuild resource location links (dry run, then apply) | `php artisan resources:relink-locations` / `--apply`            |
| Apply new migrations after pulling code               | `php artisan migrate`                                           |
| Clear cached configuration after editing `.env`       | `php artisan config:clear`                                      |
| Run one PHP test file                                 | `php artisan test --compact tests/Feature/PlanningChatTest.php` |
| Run the frontend tests                                | `node --test tests/Frontend/*.test.cjs`                         |
| Type-check, lint and format the frontend              | `npx tsc --noEmit`, `npm run lint`, `npm run format`            |
| Format PHP                                            | `./vendor/bin/pint`                                             |

**Testing notes.** `phpunit.xml` pins `AI_PROVIDER=openai` with faked responses, so tests never reach the GATES gateway or spend credit. Tests that use `RefreshDatabase` currently stop at setup, because the planning migration's foreign key needs the imported `municipalities` table. The feature tests that build their own in-memory schema (AI, GeoAgent, saved plans, map) run normally.

**After pulling new code:** run `composer install`, `npm install`, `php artisan migrate`, `npm run build` and `php artisan read-cache:clear`.

---

## 4. User manual

### 4.1 Signing in and roles

- Open the site and choose **Log in**, or **Register** to create an account (new accounts get the `user` role).
- **Users** and **technicians** get the user workspace: Dashboard, Intelligence Map, assessments and GeoAgent.
- **Admins** get the admin workspace with data management; an admin assigns roles in **Admin › Users**.

### 4.2 Dashboard

- **Start a location assessment:** search for a city or municipality and choose **Run assessment**.
- Platform coverage: how many provinces, municipalities, barangays, facilities, laboratories, cell sites and roads are recorded.
- The DOST technology catalog and STI programs, and charts of DOST project history.
- **Your planning activity:** your saved action plans and GeoAgent conversations. **Open** reopens one.

### 4.3 Intelligence Map

- Search for any province, city/municipality or barangay, or click the map to drill down. The selection is kept in the page address, so you can share or bookmark it.
- **Map markers and legend** turns layers on and off: health, education and safety facilities, laboratories, cell sites and, once a location is selected, **Roads**.
- The province resource card summarizes recorded resources and agriculture estimates. **View location assessment** opens the assessment for the selected place.

### 4.4 Location assessment

The assessment page has a summary at the top and five steps:

| Step                                  | What it shows                                                                                                                    |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Layer A · Area & assets               | Population, area, classification, available datasets and recorded assets.                                                        |
| Layer B · Sector needs                | Each indicator (e.g. health facilities per 100,000 people) against its national reference rate.                                  |
| Layer C · Priority needs & areas      | Development gaps with severity (0–100), and the ranked target areas: barangays for a city, cities/municipalities for a province. |
| Layer D · DOST catalog                | The DOST technologies and STI programs that could apply.                                                                         |
| Layer E · Technology & laboratory fit | Which catalog options match the gaps, how well (0–100), and the laboratory support pathway.                                      |

- **At a glance** cards show the top need, best-fit technology, where to deploy first, and how much of the evidence is comparable. Click a card to jump to its step.
- **How was this computed?** shows every formula with this location's real numbers.
- **AI interpretation** (Layers C and E) explains the computed results in plain language. It never changes the numbers.
- Warnings at the top flag missing population data or conflicting inventory locations.

**Action plan.** **View action plan** opens the plan: priority areas, critical needs, recommended options, a 12-week proposed schedule (Validate, Prepare, Pilot, Review), the activity matrix and expected outcomes.

- Opening the plan **saves it automatically** to your account as a version. The footer confirms "Saved as version N". An unchanged plan is not saved twice.
- **Download** offers two PDFs:
    - **Action plan:** targets, technologies, timeline and matrix.
    - **Full assessment report:** every step, chart and formula.

### 4.5 GeoAgent (AI planning assistant)

1. Click **Talk to GeoAgent** (bottom-right button or sidebar) and choose a municipality. On an assessment page it is chosen for you.
2. Ask in English or Filipino, for example:
    - "What are the biggest gaps here?"
    - "Which DOST technology fits this municipality best?"
    - "Where should we deploy it first?"
    - "Compare with Quezon City."
    - "Draft an action plan with a 6-month timeline."
3. GeoAgent runs the same analysis tools as the assessment and **answers only from KonektaDOST's recorded data**. If something is not recorded, it says so instead of guessing.
4. A complete plan (all five sections and the activity matrix) is **saved automatically** as a new version. Use **View large** to read it and **Download PDF** to export it.
5. **History** resumes earlier conversations, **Saved plans** opens earlier versions, and **New conversation** starts over without deleting anything. **Stop** cancels a reply in progress.

GeoAgent plans one municipality at a time. To plan elsewhere, change the municipality in the picker.

### 4.6 Generated action plans

The sidebar's **Generated action plans** lists all your saved plans, newest first:

- **GeoAgent** plans open in the chat window.
- **Assessment** plans open in a viewer with **Download PDF** and **Open assessment**.

Use the arrow buttons to page through older plans.

### 4.7 Admin workspace

- **Overview:** platform usage (accounts, GeoAgent activity, saved plans, most-planned locations).
- **Intelligence Map:** the same explorer as in the user workspace.
- **Data entries:** add, edit and delete records for health, education and safety facilities, laboratories and their services, cell sites, DOST technologies, STI programs, DOST projects and agriculture estimates. A record saved without a location gets its city/municipality and barangay from its coordinates.
- **Users:** manage accounts and roles.

### 4.8 Reading the results correctly

- **No record is not zero.** "No cell site recorded" does not prove there is no signal; missing road data does not prove isolation.
- **Reference rates are comparisons.** National reference rates are computed from the imported data, not official minimum standards.
- **Agriculture figures are modeled estimates**, not observed yields.
- **Scores rank screening priority.** Gap severity, target-area and match scores are not probabilities of success.
- **Project history is background.** Historical DOST projects are evidence, not technologies available now.
- **Plans are drafts.** Schedules, costs, partners and sites are proposals until the LGU and DOST confirm them.

---

## 5. More documentation

| File                                                   | Contents                                                                                |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------- |
| [CLAUDE.md](CLAUDE.md)                                 | Current technical guide: architecture, data, conventions and backlog (most up to date). |
| [FUNCTION_TOOLS_README.md](FUNCTION_TOOLS_README.md)   | Formulas, scoring and worked examples for every analysis tool.                          |
| [AI_USAGE_README.md](AI_USAGE_README.md)               | Guide for using Claude on claude.ai with KonektaDOST exports.                           |
| [docs/PLANNING_CHAT.md](docs/PLANNING_CHAT.md)         | GeoAgent configuration and behaviour.                                                   |
| [docs/MAP_EXPLORER.md](docs/MAP_EXPLORER.md)           | Intelligence Map setup, terrain and imagery.                                            |
| [docs/caching.md](docs/caching.md)                     | How the read cache works.                                                               |
| [gates_llm-0.2.0/README.md](gates_llm-0.2.0/README.md) | GATES SDK manual (the gateway's API).                                                   |

`AGENTS.md` and `WEBSITE_README.md` predate the current version and are partly out of date.

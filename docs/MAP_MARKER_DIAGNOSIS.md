# Map marker diagnosis — 2026-10-27

> **Resolved 2026-11-06.** The stored links pointed at the legacy `old_municipalities`/`old_barangays` IDs (their foreign keys still referenced those tables). `php artisan resources:relink-locations --apply` rebuilt them from coordinates (cell sites from their source barangay PSGC): 19,529 facilities now sit in the municipality polygon that contains them, 122 with coordinates outside every polygon are unlinked (NULL). Foreign keys now reference `municipalities`/`barangays`. Inventories and assessments now agree with the map. The analysis below describes the state before the fix.

The marker renderer and visibility controls work. The current imported resource location links are inconsistent with the current geography tables, so scoped queries exclude resources from their real area or return coordinates outside the selected viewport.

## Confirmed examples

- Current municipality **961** is **Pilar, Bohol**. Cell site **1** stores that municipality ID, but its source barangay PSGC **1381500020** resolves through the newest PSA release's explicit parent correspondence link to **City of Taguig**, municipality **137**. This is an identity mismatch, not a checkbox or clustering issue.
- Abra's current resource response returns **167 facilities**, with sample coordinates around **125.25, 10.35**, outside Abra's stored bounds (**120.47–121.12 longitude, 17.16–17.98 latitude**).
- The NCR resource response returns **0 laboratories**, **0 cell sites**, **90 facilities** and **31 technology reference markers**. These are the existing stored-key query results, not verified physical inventories.
- Every resource with both a municipality ID and a linked barangay has conflicting stored parent IDs: **19,095 facilities**, **51 laboratories** and **146 cell sites**. Some municipality references are also dangling. Do not resolve these conflicts by name or PSGC prefix.

## Browser verification

The actual `PhilippineMap` component was rendered in a temporary headless browser using current NCR geography and resource-service output. Technology icons rendered. **Hide all** produced zero rendered resource features; **Show all** restored markers; the camera remained unchanged. No marker rendering errors were recorded. This was an isolated component check with read-only service data, not a change to authentication or production routes.

Screenshot: [browser check](../storage/app/diagnostics/map-marker-browser-check.png).

## Read-only correction report

Run from the application root:

```powershell
php scripts/audit_resource_locations.php
```

This script reads current geography and resource rows, and writes derived reports under `storage/app/diagnostics/`. It has no database update path. It compares recorded coordinates against stored municipality polygons (including holes) and uses explicit source barangay PSGC/PSA parent correspondence links when available. It does not match place names or infer membership from code prefixes, and does not load barangay polygons.

| Dataset | Proposed municipality corrections | Needs review | Excluded overseas records |
| --- | ---: | ---: | ---: |
| Facilities | 19,517 | 142 | 0 |
| Laboratories | 52 | 12 | 5 |
| Cell sites | 155 | 0 | 0 |

Of the cell-site proposals, **146** use explicit source PSGC/PSA parent links; **9** are spatial proposals. Facility/laboratory proposals are advisory spatial matches to a single stored polygon, not authoritative administrative corrections. Boundary overlaps, absent coordinates and missing polygon matches remain for manual review. A municipality proposal does not verify an existing barangay assignment.

- [Row-by-row CSV](../storage/app/diagnostics/resource-location-audit.csv): current ID/name, proposed municipality ID/name/PSGC/province, evidence, coordinates and review status.
- [Summary JSON](../storage/app/diagnostics/resource-location-audit.json).

The CSV was checked for **19,883** records, with no duplicate record keys or malformed rows. No imported database rows have been changed. Review proposals before applying any reference-data repair; preserve original IDs and leave ambiguous records unresolved.

## Read-only map display fix

The map now fetches a national valid-coordinate inventory through `/admin/search-map/markers`, independently of the inconsistent imported foreign keys. No selection shows all pins; province, city and barangay selections use exact selected-polygon intersection, respecting holes and islands. For a barangay without geometry, an exact explicit source PSGC match is the only fallback. Matching point extents can frame those records without drawing an invented boundary. The compact toolbar legend toggles four categories. Stored assignments and inventory/assessment queries remain unchanged.

A browser check with the actual component and current read-only data confirmed **19,897** national point records, **4,238** inside NCR, **321** inside Taguig and **6** inside Bagumbayan. Fort Bonifacio lacks a boundary; its **8** explicitly source-linked cell sites are framed and render as points/clusters. No individual rendered point lay outside a selected polygon; Hide all removed all resource features without moving the camera. These are coordinate-based display counts, not corrected administrative inventories.

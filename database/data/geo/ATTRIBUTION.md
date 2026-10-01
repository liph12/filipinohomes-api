# Boundary data attribution

The admin maps draw administrative polygons from **geoBoundaries**, an open
boundary database published by the William & Mary geoLab. Both files below are
the project's *simplified* releases (the full-resolution ones are an order of
magnitude larger and render no better at the zoom levels we serve).

Attribution is a licence condition for both files. The UI carries the single
neutral string `Boundaries © geoBoundaries (CC BY)` because one map can draw
polygons from both files at once; the exact licence of each file is here.

## Files

### `database/data/geo/geoBoundaries-PHL-ADM2_simplified.geojson` — provinces

| | |
|---|---|
| Level | ADM2 (provinces + independent cities) |
| Features | 87 — 81 provinces, 4 NCR congressional districts, City of Isabela, Cotabato City |
| Release | geoBoundaries 2020 (PHL ADM2, simplified) |
| Licence | **CC BY 3.0 IGO** |
| Upstream sources | NAMRIA / PSA, redistributed via OCHA |
| Size | 3,140,454 bytes |
| sha256 | `fa77b9f17db2e419acaae714a935f7812be4409e2983675d34020e8426a3e189` |
| Committed | yes — the province import runs on servers that have no copy otherwise |

The four `NCR, … District` features and the two single-city features are folded
onto real `provinces` rows by `App\Support\ProvinceCanonicalizer`, which is why
the import writes **80** rows rather than 87. Read that class before changing
anything here; its docblock records which rows this database actually has.

### `storage/app/geoBoundaries-PHL-ADM3_simplified.geojson` — cities / municipalities

| | |
|---|---|
| Level | ADM3 (cities and municipalities) |
| Features | 1,647 |
| Licence | **CC BY 4.0** |
| Size | 7,071,267 bytes |
| sha256 | `2ece3d44a5c6a2afb385ffbf3a6b88d83e4d3a3e7eed9a52cb3be1bc59e289fc` |
| Committed | no — git-ignored; copy it to `storage/app/` before re-importing |

## Why the ADM2 file is committed and the ADM3 file is not

The ADM3 file predates this directory and already lives outside version control;
re-importing cities is rare and the operator copies the file across by hand. The
province file is new, is a third of the size, and the import is part of the
heatmap deployment runbook — a server that cannot reach the internet still has
to be able to run it. `database/data/listings.php` (5 MB) is the precedent for
committing a data file of this size.

## Re-importing

```
php artisan boundaries:import database/data/geo/geoBoundaries-PHL-ADM2_simplified.geojson --level=province
php artisan boundaries:import storage/app/geoBoundaries-PHL-ADM3_simplified.geojson --level=city
php artisan boundaries:relink-cities --dry-run    # inspect, then run without the flag
```

Province polygons must exist before `boundaries:relink-cities` runs: its first
and cheapest pass places a city polygon by asking which province polygon
contains its centroid.

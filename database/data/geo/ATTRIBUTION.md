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

### `storage/app/psa-namria-PHL-ADM4_2023_simplified.ndjson` — barangays

A different publisher from the two files above: this one is the Philippine
government's own boundary set, not geoBoundaries, so it carries its own credit
line and licence.

| | |
|---|---|
| Level | ADM4 (barangays) |
| Features | 42,048 |
| Source | Philippine Statistics Authority (PSA) and the National Mapping and Resource Information Authority (NAMRIA), shapefile `phl_admbnda_adm4_psa_namria_20231106`, distributed by OCHA through the Humanitarian Data Exchange |
| Licence | **CC BY-IGO** — credit PSA and NAMRIA |
| Size | 57,701,758 bytes |
| sha256 | `8aaf96aa2df2d0d84a2ea096633f1bf1e99aaa00274e2aff3821f67ba2aaadfe` |
| Committed | no — git-ignored; copy it to `storage/app/` before re-importing |

Generated from the full-resolution shapefile, which is 272 MB and is not kept
anywhere in this repository. To regenerate it exactly:

```
BASE=https://media.githubusercontent.com/media/altcoder/philippines-psgc-shapefiles/main/data/2023/BgySubMuns/phl_admbnda_adm4_psa_namria_20231106
mkdir -p ~/geo/adm4 && cd ~/geo/adm4
for ext in shp dbf shx prj cpg; do curl -L --retry 3 -o adm4.$ext "$BASE.$ext"; done
shasum -a 256 adm4.shp adm4.dbf
#   a90587b5bf1579e6afb0309282f5e39d33c9c5d3974e32868eb9dfe30ef3b0e6  adm4.shp   (272,270,908 bytes)
#   ee46053ed430674c6d48cbc86fd5cac37ee0725d09429637acd52f67694dee0c  adm4.dbf   ( 43,730,498 bytes)

npx -y -p mapshaper@0.7.72 mapshaper-xl 10gb \
  -i adm4.shp encoding=utf8 snap \
  -filter-fields ADM4_EN,ADM4_PCODE,ADM3_EN,ADM3_PCODE,ADM2_EN,ADM2_PCODE \
  -clean gap-width=15m \
  -simplify weighted keep-shapes interval=15 stats \
  -o psa-namria-PHL-ADM4_2023_simplified.ndjson format=geojson ndjson precision=0.00001 fix-geometry
```

mapshaper is the tool here rather than a per-shape simplifier because it builds
shared arcs on import: a border between two barangays is simplified once and
stays coincident. A per-polygon pass leaves gaps and overlaps between
neighbours, which is why the map never re-simplifies barangay geometry at serve
time either. The run keeps every feature and removes 90.9% of the 14.9M
vertices; two polygons (Kisupaan in President Roxas, Ambadao in Datu Piang) stay
geometrically invalid afterwards, which is harmless — every spatial call in the
re-link is guarded, and neither carries listings.

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

php artisan boundaries:import storage/app/psa-namria-PHL-ADM4_2023_simplified.ndjson --level=barangay
php artisan boundaries:relink-barangays --dry-run  # inspect, then run without the flag
```

Province polygons must exist before `boundaries:relink-cities` runs: its first
and cheapest pass places a city polygon by asking which province polygon
contains its centroid.

City polygons must be linked before `boundaries:relink-barangays` runs, for the
same reason one level down: it places a town's barangays by asking which linked
city polygon contains their centroids, and falls back to matching the town's
name inside that province only when the vote is absent or split.

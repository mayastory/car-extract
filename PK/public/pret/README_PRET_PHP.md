# PRET (Packege) PHP map export

- API list: `/api/pret/list.php`
- API map : `/api/pret/map.php?map=PalletTown`

Generated outputs are written to:
- `public/pret/maps/<MapId>.json`
- `public/pret/tilesets/<primary>__<secondary>__<MapId>.png`

If `public/pret/index.json` exists (from python export), the client will use it first.

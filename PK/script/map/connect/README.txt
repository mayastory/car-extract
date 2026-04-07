This folder holds rAthena-like CONNECT scripts (seamless map boundary scroll).

Format (tabs/spaces ok, // comments ok):
  SrcMap    connect    <up|down|left|right>    DestMap    Offset

Example:
  PalletTown   connect   up     Route1        0 // src=MAP_ROUTE1

We do NOT use an "auto" subfolder.
Keep all connect scripts directly in:
  script/map/connect/

If you want to (re)generate from Packege, run an external one-time tool and
write outputs directly into this folder (no auto subdir).

Notes:
- DestMap can be a folder id (Route1) OR a MAP_* constant (MAP_ROUTE1).
- Offset meaning matches decomp map.json connections.

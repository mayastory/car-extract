# NPC / WARP scripts (rAthena-like)

이 프로젝트는 디컴파일( Packege )에서 점진적으로 분리하기 위해,
NPC / WARP 정의를 텍스트 스크립트로 관리합니다.

## WARP
경로:
- `script/map/warp/<Map>.warp` (수동 관리)
- `script/map/warp/auto/<Map>.warp` (Packege 기반 자동생성)

라인 형식:
`Map,x,y,dir    warp    Name    w,h,DestMap,DestX,DestY[,DestDir]`

예시:
`PalletTown,6,7,0    warp    toHouse    1,1,PalletTown_PlayersHouse_1F,4,8`

자동 생성:
- `python tools/gen_warp_scripts.py  # outputs to script/map/warp/auto`

## CONNECT (맵경계 스크롤/연결)
경로:
- `script/map/connect/<Map>.connect` (수동 관리)
- `script/map/connect/auto/<Map>.connect` (Packege 기반 자동생성)

라인 형식:
`SrcMap    connect    <up|down|left|right>    DestMap    Offset`

예시:
`PalletTown    connect    up    Route1    0`

자동 생성:
- `python tools/gen_connect_scripts.py  # outputs to script/map/connect/auto`

## MONSTER (필드 몬스터 스폰)
경로:
- `script/monster/<Map>.monster` (수동)
- `script/monster/auto/<Map>.monster` (Packege wild_encounters 기반 자동생성)

라인 형식:
`Map,x,y,dir    monster    Name    w,h,Species,lvMin,lvMax,count,respawnSec[,flags]`

자동 생성:
- `python tools/gen_monster_scripts_from_wild.py  # outputs to script/monster/auto`

## 목적
- 지금은 Packege map.json과 동일하게 동작하도록 auto 스크립트를 생성하고,
- 차차 수동 스크립트로 옮기면서 Packege 의존을 줄입니다.

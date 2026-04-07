# Monster spawn scripts (rAthena-like)

필드(오버월드)에 보이는 몬스터 스폰을 텍스트 스크립트로 관리합니다.

경로:
- `script/monster/<Map>.monster`

※ "auto" 폴더는 쓰지 않습니다. 생성/수정 모두 이 폴더에 직접 넣습니다.

## 라인 형식

`Map,x,y,dir    monster    Name    w,h,Species,lvMin,lvMax,count,respawnSec[,flags]`

- `Map,x,y,dir`: 스폰 영역의 좌상단 타일 좌표/방향
- `Name`: 스폰 그룹 이름(임의)
- `w,h`: 스폰 영역 크기(타일)
- `Species`: `SPECIES_PIDGEY` 처럼 const_name 또는 숫자 species_id
- `lvMin, lvMax`: 레벨 범위
- `count`: 동시에 유지할 몬스터 수
- `respawnSec`: 죽은 뒤 리스폰 대기(초)
- `flags`: 옵션(현재는 저장만; 향후 land/water 등으로 확장)

## 예시

`Route1,0,0,0    monster    land_pidgey    40,60,SPECIES_PIDGEY,2,4,4,30,land`

## 테스트

- `GET /api/rt/map_mobs.php` (Bearer 토큰 필요)
- `POST /api/rt/mob_kill.php` `{ "mob_id": 123 }` (리스폰 테스트용)

## (참고) 생성/업데이트

Packege의 `wild_encounters.json` 기반으로 1회성 생성툴을 돌려 스크립트를 뽑을 수 있지만,
출력은 항상 `script/monster/*.monster` 로 직접 저장하는 방식만 유지합니다.

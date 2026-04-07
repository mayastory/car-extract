# PK Packege Migration Guide

이 문서는 `PK/Packege` 폴더를 나중에 분리/삭제하기 전에,
현재 런타임이 무엇에 기대고 있는지와 무엇을 먼저 영구 export 해야 하는지를 정리한 기준 문서다.

## 목표

- 최종 런타임은 `Packege`를 직접 읽지 않는다.
- 최종 런타임은 아래 3가지만 신뢰한다.
  - `public/pret/*` : 맵/타일셋/스프라이트 export 결과물
  - `script/npc/*` : 런타임용 NPC/이벤트 스크립트 캐시
  - `sql/*` + DB : 로그인/맵 정보/레퍼런스 테이블
- `Packege`는 **추출용 소스**로만 남기다가, export contract가 안정화되면 제거한다.

## 지금 당장 지우면 안 되는 이유

현재 PK는 이미 PHP 런타임으로 많이 옮겨져 있지만,
맵/타일셋/스프라이트/NPC 스크립트는 아직 `Packege` 기반 export 흐름을 전제로 정리되어 있다.

따라서 `Packege` 제거 전에 반드시 아래 산출물이 **프로젝트 내부에 완결된 형태**로 남아 있어야 한다.

## Packege 제거 전 필수 보존 대상

### 1. 맵 export
- `public/pret/maps/<MapId>.json`
- `public/pret/index.json`
- 요구사항
  - 클라이언트는 `Packege` 없이도 맵 레이아웃/워프/충돌/라벨을 읽을 수 있어야 한다.
  - `index.json` 또는 동등한 인덱스가 있어야 맵 목록/검색이 안정적이다.

### 2. 타일셋 export
- `public/pret/tilesets/<primary>__<secondary>__<MapId>.png`
- 요구사항
  - 맵 렌더링이 `Packege/graphics/*`를 직접 보지 않아야 한다.
  - 맵별 필요한 타일셋 조합이 export 결과물만으로 복원되어야 한다.

### 3. 플레이어/NPC 스프라이트
- `public/pret/sprites/player/*`
- `public/pret/sprites/npc/*`
- 요구사항
  - 걷기/방향/프레임 결정은 런타임 로직으로 하되,
    sprite source는 `public/pret/sprites/*`만 참조하도록 정리한다.

### 4. NPC / 이벤트 스크립트 캐시
- `script/npc/<ver>/npc/*.npc`
- `script/npc/<ver>/event/*.npc`
- 요구사항
  - 맵 object/event가 말풍선/이벤트를 실행할 때 `Packege` asm/inc를 직접 파싱하지 않아야 한다.
  - `tools/packege_sync.py` 같은 추출 툴은 남아도 되지만,
    `public/*`/`api/*` 런타임이 `Packege`를 직접 요구하면 안 된다.

### 5. DB bootstrap 및 레퍼런스
- `sql/pokemon_full_reset.sql`
- `maps_info` seed
- `ref_species`, `ref_move`, `ref_item` 등 배틀/도감/인벤토리용 참조 테이블
- 요구사항
  - 새 환경에서도 import 한 번으로 최소 플레이 가능 상태가 되어야 한다.
  - `Packege`가 없어도 기본 참조 테이블이 DB에서 올라와야 한다.

## 추천 작업 순서

### Phase 1. 계약 고정
1. `public/pret/*` 와 `script/npc/*` 를 현재 런타임 기준 산출물로 확정한다.
2. 어떤 파일이 소스 오브 트루스인지 문서화한다.
3. `tools/packege_inventory.py` 로 의존성 보고서를 뽑는다.

### Phase 2. 오버월드 완성도 상승
1. 플레이어/NPC sprite meta를 별도 export 가능 구조로 정리한다.
2. 방향/걷기/러닝/서핑 등은 런타임 state machine에서 처리한다.
3. 스프라이트 이미지는 `public/pret/sprites/*`만 바라보게 한다.

### Phase 3. 맵/NPC 독립
1. 클라이언트/서버가 `public/pret/maps/*` 와 DB만으로 맵 진입 가능하도록 만든다.
2. NPC 대화/이벤트는 `script/npc/*` 캐시만으로 실행 가능하도록 만든다.
3. `Packege` direct path를 runtime code에서 제거한다.

### Phase 4. 배틀 authoritative 정리
1. `ref_*` 테이블과 전투 state machine을 서버 기준으로 붙인다.
2. 배틀 화면은 iframe/임시 연결이 아니라 런타임 상태 기반으로 움직이게 바꾼다.
3. 전투/맵/인벤토리 모두 DB + export 데이터만 사용하도록 맞춘다.

### Phase 5. Packege 제거
1. `tools/packege_inventory.py --strict` 통과
2. 직접 `Packege` 경로를 읽는 runtime code 제거 확인
3. 그 다음에만 `Packege` 분리/삭제

## 점검 명령

```bash
python tools/packege_inventory.py
python tools/packege_inventory.py --json-out tools/packege_inventory_report.json
python tools/packege_inventory.py --strict
```

## 이번 단계에서 잡은 기본 원칙

- `Packege`는 당장 없애는 대상이 아니라, **추출 완료 후 제거할 소스 폴더**다.
- 앞으로의 구현은 “직접 decomp 폴더를 읽는 런타임”이 아니라,
  “decomp에서 export된 데이터를 읽는 런타임”으로 간다.
- 걷기/방향/배틀 규칙은 기억에 의존하지 않고,
  export 데이터 + 런타임 state machine으로 재현한다.

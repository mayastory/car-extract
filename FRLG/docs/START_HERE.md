# FRLG starter patch

이번 스타터는 **완성형 FRLG 구현이 아니라**, 현재 `FRLG/Packege`를 기준으로
나중에 확장 가능한 **로컬 PHP 클라이언트 + intro scene runner 골격**을 먼저 놓는 목적입니다.

## 포함 파일
- `FRLG/client/index.php`
- `FRLG/client/assets/frlg.css`
- `FRLG/client/assets/intro-scenes.js`
- `FRLG/client/assets/intro-engine.js`
- `FRLG/client/assets/app.js`
- `FRLG/sql/001_runtime_core.sql`

## 지금 되는 것
- PHP로 바로 열리는 로컬 클라이언트 엔트리
- Canvas 기반 intro scene sequencer
- 장면 강제 전환(Enter/Space), 재시작(R)
- 나중에 Packege 추출 자산으로 교체 가능한 구조

## 아직 안 되는 것
- Packege 자산 실제 추출/연결
- 원본 intro 풀재현
- title screen, map loader, collision, warp
- SQL 저장 API

## 다음 추천 순서
1. Packege 자산 추출기 만들기
2. title scene 추가
3. PalletTown 1맵 로더
4. warp / collision
5. SQL save/load API

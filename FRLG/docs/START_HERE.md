# FRLG starter patch 2

이번 패치는 **Packege 자산 추출 전 단계**에서,
로컬 PHP 클라이언트가 최소한 아래 순서를 실제로 밟도록 만드는 목적입니다.

- intro
- title
- main menu
- new game
- 오박사 오프닝
- 성별 선택
- 플레이어 이름
- 라이벌 이름
- PlayersHouse 2F 시작

## 포함 파일
- `FRLG/client/index.php`
- `FRLG/client/assets/frlg.css`
- `FRLG/client/assets/intro-scenes.js`
- `FRLG/client/assets/intro-engine.js`
- `FRLG/client/assets/app.js`

## 지금 되는 것
- PHP로 바로 열리는 로컬 클라이언트 엔트리
- intro scene 자동 진행 + 수동 넘김
- title → main menu → new game 흐름
- 오박사 대사 / 성별 선택 / 이름 입력 / 라이벌 이름 입력
- 최종적으로 `PalletTown_PlayersHouse_2F` 시작점 도착
- 방 내부 placeholder 이동 테스트
- localStorage 임시 저장

## 아직 안 되는 것
- Packege 자산 실제 추출/연결
- 원본 intro/title 그래픽/팔레트 재현
- 실제 방 맵 json / tileset / collision 적용
- SQL save/load API
- Oak 이후 실제 집 내부 이벤트/팔렛타운 연결

## 다음 추천 순서
1. Packege 자산 추출기 만들기
2. PlayersHouse_2F 실제 map/json 연결
3. 계단 내려가기 / 1F 연결
4. PalletTown 연결
5. SQL save/load API

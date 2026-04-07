# db 폴더 정책 (추천: MySQL = 정본)

이 프로젝트는 rAthena처럼 **서버(MySQL)가 정본**이 되는 구조가 가장 관리가 편합니다.

- `sql/` : DB 스키마 + 시드(seed)
- `db/generated/` : Packege(디컴)에서 추출한 레퍼런스 CSV 백업 (Packege를 떼어낼 때 보험용)
- `db/_legacy/` : 예전에 쓰던 txt/임시 DB 파일 보관(현재 로직에서는 미사용)

## 지금 이 폴더에서 “필요한 것”
- `db/generated/ref_item.csv` : 아이템(샵 가격/표시)
- `db/generated/ref_move.csv` : 기술명(표시)
- `db/generated/ref_species.csv` : 포켓몬명(표시)

이 3개는 `tools/build_ref_seed.py`가 Packege에서 자동 생성합니다.

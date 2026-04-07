# 적용 방법 (DB 정리 + ref 시드)

이 패치의 목적:
- `ref_item / ref_move / ref_species`를 **Packege에서 뽑아 MySQL에 시드**해두고,
- `db/` 폴더는 **generated CSV 백업 + legacy 보관**으로 정리해서,
- 나중에 Packege를 점차 떼어내도 **샵/표시/스크립트**가 DB만 보고 돌아가게 만들기.

---

## 1) DB 시드(둘 중 하나)

### A안 (추천): `pokemon_full_reset.sql`를 이걸로 교체해서 Import
1. `sql/pokemon_full_reset.sql` 를 패치본으로 덮어쓰기
2. phpMyAdmin에서 이 SQL Import 실행

→ maps_info + ref_* 테이블까지 한번에 채워짐

### B안: 기존 reset.sql 유지 + ref만 별도로 Import
1. 기존대로 `pokemon_full_reset.sql` Import
2. 이어서 `sql/seed_ref_generated.sql` Import

---

## 2) db 폴더 정리(선택)
기존에 `db/item_db.txt` 같은 레거시 txt가 있으면 혼동되니 아래 실행:

```
python tools/db_cleanup.py
```

→ `db/_legacy/`로 자동 이동

---

## 3) Packege를 점차 떼어낼 때의 순서(권장)
1) **ref_* 시드 완료** (이번 패치로 해결)
2) 맵/타일/스프라이트를 `public/pret/`로 “Export 결과”를 정본화
3) 서버는 Packege 직접 읽지 않고, **export 결과 + MySQL**만 보게 고정
4) 마지막에 Packege 폴더 삭제/분리

---

## 참고
Packege 기반 ref 시드를 다시 만들고 싶으면:

```
python tools/build_ref_seed.py
```

→ `sql/seed_ref_generated.sql` + `db/generated/*.csv` 재생성

# A-Architecture Patch v1 (Auth + World WS)

이 ZIP은 **pokemon_hybrid_web** 프로젝트 루트에 그대로 덮어쓰면 됩니다.

## 1) 덮어쓰기
- 압축을 풀고, 나온 폴더/파일들을
  `C:\xampp\htdocs\pokemon_hybrid_web\` 에 그대로 **병합/덮어쓰기** 하세요.

## 2) 토큰 시크릿 맞추기 (권장)
`C:\xampp\htdocs\pokemon_hybrid_web\api\config.local.php` 생성:

```php
<?php
return [
  "token_secret" => "dev_secret_change_me",
];
```

World 서버도 같은 시크릿 사용:
- `server_world\\.env.example`를 복사해서 `.env` 만들고 `TOKEN_SECRET`을 동일하게 설정

## 3) Packege -> pret 캐시 생성 (1회 또는 갱신 시)
프로젝트 루트에서:

```bat
python tools\\export_pret.py --project . --map PalletTown
```

## 4) World 서버 실행
```bat
cd server_world
copy .env.example .env
npm i
node index.js
```

## 5) 테스트 페이지
브라우저:
- `http://localhost/pokemon_hybrid_web/public/mmo_test.html`

로그인 -> WS접속 -> 방향키(↑↓←→) 이동


## 6) (추가) WARP 스크립트 자동 생성/갱신
프로젝트 루트에서:

```bat
python tools\\gen_warp_scripts.py
```

- 생성 위치: `npc\\map\\warp\\auto\\*.warp`
- 이제 맵 로딩 시 warp_events는 **스크립트가 있으면 스크립트를 우선** 사용합니다.


## 7) (추가) CONNECT(스크롤/맵경계 연결) 스크립트 자동 생성/갱신
프로젝트 루트에서:

```bat
python tools\\gen_connect_scripts.py
```

- 생성 위치: `npc\\map\\connect\\auto\\*.connect`
- 이제 맵 로딩 시 connections는 **스크립트가 있으면 스크립트를 우선** 사용합니다.


## Pokemon assets (아이콘/배틀/팔레트) Packege -> public 1회 추출

```bat
py tools\extract_pokemon_assets_to_public.py
```

- 생성 위치: `public/assets/pokemon/<species_folder>/...`
- 이후 Packege를 제거해도 런타임은 public 자산을 사용합니다.

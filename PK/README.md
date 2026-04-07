# pokemon_web_clean (PHP)

이 ZIP은 기존 `pokemon_hybrid_web`에서 **헷갈리던 로컬/JSON 의존을 끊고**,
**DB(MySQL) 중심 + 4슬롯 캐릭터 선택 로그인** 흐름으로 정리한 버전입니다.

## 1) DB 초기화 (한 파일)
- `sql/pokemon_full_reset.sql` 를 **그대로 import** 하면 됩니다.
  - 기본 DB 이름은 `pokemon` 로 되어있고, 필요하면 SQL 상단의 `CREATE DATABASE/USE` 를 수정하세요.

## 2) API DB 설정
- `api/config.local.php` 를 만들어서 DB 계정/비밀번호를 넣으세요.
  - 예시:
    ```php
    <?php
    return [
      'db_host' => '127.0.0.1',
      'db_user' => 'root',
      'db_pass' => '',
      'db_name' => 'pokemon',
      'token_secret' => 'CHANGE_ME_RANDOM',
      'local_only_no_login' => true,
    ];
    ```

## 3) 실행
- 웹 root 에서 `public/` 을 열면 됩니다.
- `public/index.html` 은 `play_token` 이 없으면 자동으로 `public/login.html` 로 이동합니다.

## 4) 캐릭터(4 슬롯)
1) `login.html` 에서 계정 로그인
2) 슬롯 1~4에서 캐릭터 생성/선택
3) 선택하면 `play_token` 발급 → `index.html` 접속

## 5) maps_info 생성/갱신
- 기본은 `pokemon_full_reset.sql` 에 이미 maps_info seed가 들어있습니다.
- Packege가 바뀌면:
  - `python tools/gen_maps_info_sql.py > sql/maps_info_seed.sql`
  - 또는 seed를 다시 만들어 import


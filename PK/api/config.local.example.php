<?php
// api/config.local.php 예시
// 이 파일을 `api/config.local.php`로 복사한 뒤, 아래 값을 자신의 MySQL 환경에 맞게 수정하세요.
//
// XAMPP 기본: host=127.0.0.1, user=root, pass='', db='pokemon'
return [
  'host' => '127.0.0.1',
  'user' => 'root',
  'pass' => '',
  'db'   => 'pokemon',

  // (선택) JWT 토큰 시크릿 (프로덕션에서는 꼭 변경)
  // 'token_secret' => 'change_me_to_random_long_string',
];

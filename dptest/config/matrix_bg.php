<?php
// config/matrix_bg.php
// ✅ 매트릭스 배경 설정(한 곳에서만 관리)
// - text: ''  => 랜덤(가타카나/숫자/기호)
// - text: '01' => 0/1 반복 출력(원하면 여기만 바꾸면 됨)
return [
  'enabled'   => true,
  'text'      => '01',
  'speed'     => 1.15,
  'size'      => 16,
  'zIndex'    => 0,
  'scanlines' => true,
  'vignette'  => true,
];

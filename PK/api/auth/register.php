<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['ok'=>true]);

$in = json_in();
require_fields($in, ['username','password']);
$username = trim((string)$in['username']);
$password = (string)$in['password'];

if ($username === '' || strlen($username) < 2 || strlen($username) > 64) json_out(['ok'=>false,'error'=>'BAD_USERNAME'], 400);
if (strlen($password) < 4) json_out(['ok'=>false,'error'=>'BAD_PASSWORD'], 400);

// Simple username policy: letters/digits/underscore only
if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) json_out(['ok'=>false,'error'=>'BAD_USERNAME_CHARS'], 400);

$conn = db();

// Exists?
$stmt = $conn->prepare('SELECT account_id FROM account WHERE username=? LIMIT 1');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('s', $username);
$stmt->execute();
$res = $stmt->get_result();
$ex = $res ? $res->fetch_assoc() : null;
$stmt->close();
if ($ex) json_out(['ok'=>false,'error'=>'USER_EXISTS'], 409);

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare('INSERT INTO account (username, password_hash) VALUES (?, ?)');
if (!$stmt) json_out(['ok'=>false,'error'=>'DB_PREPARE_FAIL','detail'=>$conn->error], 500);
$stmt->bind_param('ss', $username, $hash);
$ok = $stmt->execute();
$err = $stmt->error;
$newId = (int)$stmt->insert_id;
$stmt->close();
if (!$ok) json_out(['ok'=>false,'error'=>'DB_EXEC_FAIL','detail'=>$err], 500);

json_out(['ok'=>true,'account_id'=>$newId,'username'=>$username]);

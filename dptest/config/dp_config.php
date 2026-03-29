<?php
// ===== DB 설정 =====
const DP_DB_HOST    = '211.212.182.110';
const DP_DB_NAME    = 'dp';
const DP_DB_USER    = 'maya';
const DP_DB_PASS    = '##Gmlakd2323';   // ← 실제 비번
const DP_DB_CHARSET = 'utf8mb4';

/**
 * 공용 PDO 핸들 가져오기
 */
function dp_get_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = "mysql:host=".DP_DB_HOST.";dbname=".DP_DB_NAME.";charset=".DP_DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, DP_DB_USER, DP_DB_PASS, $options);
    return $pdo;
}

// ===== FTP(스크린샷용) 설정 =====
const DP_FTP_HOST = '211.212.182.110';
const DP_FTP_PORT = 21;
const DP_FTP_USER = 'maya';
const DP_FTP_PASS = 'gmlakd23';       // ← 실제 비번
const DP_FTP_DIR  = '/Update/Screenshot';

/**
 * 스크린샷 FTP 서버 연결
 * 성공: FTP 커넥션 리소스 / 실패: null
 */
function dp_ftp_connect() {
    $ftp = @ftp_connect(DP_FTP_HOST, DP_FTP_PORT, 10);
    if (!$ftp) {
        return null;
    }
    if (!@ftp_login($ftp, DP_FTP_USER, DP_FTP_PASS)) {
        ftp_close($ftp);
        return null;
    }
    @ftp_pasv($ftp, true);
    return $ftp;
}

<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Europe/Istanbul');

/* =========================================================
   AYARLAR
   ========================================================= */
$DB_HOST = 'localhost';
$DB_NAME = 'barberdb';
$DB_USER = 'root';
$DB_PASS = '';

$APP_NAME = 'Ahmet Tekeli Barber';
$ADMIN_NAME = 'Ahmet Tekeli';
$ADMIN_PHONE = '05356749243';
$ADMIN_PASSWORD = '123456';

/* =========================================================
   BAĞLANTI
   ========================================================= */
try {
    $db = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    exit('MySQL bağlantısı kurulamadı. Veritabanı bilgilerini kontrol et.');
}

/* =========================================================
   YARDIMCI FONKSİYONLAR
   ========================================================= */
function e(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function nowDate(): string {
    return date('Y-m-d');
}
function nowDateTime(): string {
    return date('Y-m-d H:i:s');
}
function cleanPhone(string $phone): string {
    return preg_replace('/\D+/', '', $phone);
}
function isLogged(): bool {
    return isset($_SESSION['user']);
}
function user(): ?array {
    return $_SESSION['user'] ?? null;
}
function go(string $url): void {
    header("Location: $url");
    exit;
}
function flashSet(string $type, string $text): void {
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}
function flashGet(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
function requireLogin(): void {
    if (!isLogged()) {
        go('?sayfa=giris');
    }
}
function requireRole(string $role): void {
    requireLogin();
    if ((user()['rol'] ?? '') !== $role) {
        go('?sayfa=panel');
    }
}
function roleText(string $role): string {
    return match($role) {
        'admin' => 'Yönetici',
        'berber' => 'Berber',
        'musteri' => 'Müşteri',
        default => $role
    };
}
function statusText(string $status): string {
    return match($status) {
        'bekliyor' => 'Bekliyor',
        'onaylandi' => 'Onaylandı',
        'tamamlandi' => 'Tamamlandı',
        'iptal' => 'İptal',
        default => $status
    };
}
function gunText(int $gunNo): string {
    return match($gunNo) {
        1 => 'Pazartesi',
        2 => 'Salı',
        3 => 'Çarşamba',
        4 => 'Perşembe',
        5 => 'Cuma',
        6 => 'Cumartesi',
        7 => 'Pazar',
        default => 'Bilinmiyor'
    };
}
function tarihGunNo(string $date): int {
    return (int)date('N', strtotime($date));
}
function baseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?');
    return $https . '://' . $host . $path;
}
function settingGet(PDO $db, string $key, string $default = ''): string {
    $s = $db->prepare("SELECT ayar_value FROM ayarlar WHERE ayar_key = ? LIMIT 1");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? $default : (string)$v;
}
function settingSet(PDO $db, string $key, string $value): void {
    $s = $db->prepare("
        INSERT INTO ayarlar (ayar_key, ayar_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE ayar_value = VALUES(ayar_value)
    ");
    $s->execute([$key, $value]);
}
function slotList30min(string $start, string $end): array {
    $slots = [];
    $current = strtotime('2000-01-01 ' . substr($start, 0, 5));
    $finish = strtotime('2000-01-01 ' . substr($end, 0, 5));
    while ($current < $finish) {
        $slots[] = date('H:i', $current);
        $current = strtotime('+30 minutes', $current);
    }
    return $slots;
}
function getBarberWorkRow(PDO $db, int $barberId, int $gunNo): ?array {
    $s = $db->prepare("
        SELECT * FROM berber_calisma_saatleri
        WHERE berber_id = ? AND gun_no = ?
        LIMIT 1
    ");
    $s->execute([$barberId, $gunNo]);
    $row = $s->fetch();
    return $row ?: null;
}
function barberOnLeave(PDO $db, int $barberId, string $date): bool {
    $s = $db->prepare("
        SELECT COUNT(*) FROM berber_izinleri
        WHERE berber_id = ?
          AND ? BETWEEN baslangic_tarihi AND bitis_tarihi
    ");
    $s->execute([$barberId, $date]);
    return (int)$s->fetchColumn() > 0;
}
function getBusySlots(PDO $db, int $barberId, string $date): array {
    $s = $db->prepare("
        SELECT saat
        FROM randevular
        WHERE berber_id = ?
          AND tarih = ?
          AND durum != 'iptal'
    ");
    $s->execute([$barberId, $date]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}
function getBarberServiceIds(PDO $db, int $barberId): array {
    $s = $db->prepare("SELECT hizmet_id FROM berber_hizmetleri WHERE berber_id = ?");
    $s->execute([$barberId]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}
function barberCanDoService(PDO $db, int $barberId, int $serviceId): bool {
    $s = $db->prepare("SELECT COUNT(*) FROM berber_hizmetleri WHERE berber_id = ? AND hizmet_id = ?");
    $s->execute([$barberId, $serviceId]);
    return (int)$s->fetchColumn() > 0;
}
function getAvailableSlots(PDO $db, int $barberId, string $date): array {
    $gunNo = tarihGunNo($date);
    $work = getBarberWorkRow($db, $barberId, $gunNo);

    if (!$work || (int)$work['aktif'] !== 1 || !$work['baslangic'] || !$work['bitis']) {
        return [];
    }

    if (barberOnLeave($db, $barberId, $date)) {
        return [];
    }

    $all = slotList30min((string)$work['baslangic'], (string)$work['bitis']);

    if (!empty($work['mola_baslangic']) && !empty($work['mola_bitis'])) {
        $mola = slotList30min((string)$work['mola_baslangic'], (string)$work['mola_bitis']);
        $all = array_values(array_diff($all, $mola));
    }

    $busy = getBusySlots($db, $barberId, $date);
    $busyNormalized = array_map(fn($x) => substr((string)$x, 0, 5), $busy);

    $available = [];
    foreach ($all as $slot) {
        if (!in_array($slot, $busyNormalized, true)) {
            $available[] = $slot;
        }
    }
    return $available;
}
function getAllVisibleSlots(PDO $db, int $barberId, string $date): array {
    $gunNo = tarihGunNo($date);
    $work = getBarberWorkRow($db, $barberId, $gunNo);

    if (!$work || (int)$work['aktif'] !== 1 || !$work['baslangic'] || !$work['bitis']) {
        return [];
    }

    if (barberOnLeave($db, $barberId, $date)) {
        return [];
    }

    $all = slotList30min((string)$work['baslangic'], (string)$work['bitis']);
    $mola = [];

    if (!empty($work['mola_baslangic']) && !empty($work['mola_bitis'])) {
        $mola = slotList30min((string)$work['mola_baslangic'], (string)$work['mola_bitis']);
    }

    $busy = getBusySlots($db, $barberId, $date);
    $busyNormalized = array_map(fn($x) => substr((string)$x, 0, 5), $busy);

    $result = [];
    foreach ($all as $slot) {
        if (in_array($slot, $mola, true)) {
            $result[] = ['saat' => $slot, 'durum' => 'mola'];
        } elseif (in_array($slot, $busyNormalized, true)) {
            $result[] = ['saat' => $slot, 'durum' => 'dolu'];
        } else {
            $result[] = ['saat' => $slot, 'durum' => 'bos'];
        }
    }
    return $result;
}
function createWhatsAppLink(string $phone, string $message): string {
    $clean = cleanPhone($phone);
    return 'https://wa.me/90' . ltrim($clean, '0') . '?text=' . rawurlencode($message);
}

/* =========================================================
   TEK DOSYADAN PWA DOSYALARI
   ========================================================= */
if (isset($_GET['manifest'])) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode([
        'name' => $APP_NAME,
        'short_name' => 'Tekeli',
        'start_url' => baseUrl() . '?sayfa=giris',
        'scope' => dirname(baseUrl()) . '/',
        'display' => 'standalone',
        'background_color' => '#0c0a0a',
        'theme_color' => '#d4af37',
        'icons' => [
            [
                'src' => baseUrl() . '?icon=192',
                'sizes' => '192x192',
                'type' => 'image/svg+xml',
                'purpose' => 'any'
            ],
            [
                'src' => baseUrl() . '?icon=512',
                'sizes' => '512x512',
                'type' => 'image/svg+xml',
                'purpose' => 'any'
            ]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['sw'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    $start = baseUrl() . '?sayfa=giris';
    echo <<<JS
const CACHE_NAME = 'tekeli-barber-v3';
const URLS = ['$start'];
self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE_NAME).then(c => c.addAll(URLS)));
  self.skipWaiting();
});
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(keys.map(k => k !== CACHE_NAME ? caches.delete(k) : null)))
  );
  self.clients.claim();
});
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  e.respondWith(
    fetch(e.request).then(r => {
      const copy = r.clone();
      caches.open(CACHE_NAME).then(c => c.put(e.request, copy));
      return r;
    }).catch(() => caches.match(e.request).then(r => r || caches.match('$start')))
  );
});
JS;
    exit;
}

if (isset($_GET['icon'])) {
    $size = (int)($_GET['icon'] ?? 192);
    if ($size < 64) $size = 192;
    header('Content-Type: image/svg+xml; charset=utf-8');
    echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="$size" height="$size" viewBox="0 0 512 512">
<rect width="512" height="512" rx="96" fill="#0f0c0c"/>
<rect x="18" y="18" width="476" height="476" rx="84" fill="none" stroke="#d4af37" stroke-width="18"/>
<text x="256" y="295" text-anchor="middle" font-size="180" font-family="Arial, Helvetica, sans-serif" font-weight="700" fill="#f0d277">AT</text>
<text x="256" y="380" text-anchor="middle" font-size="40" font-family="Arial, Helvetica, sans-serif" fill="#d4af37">BARBER</text>
</svg>
SVG;
    exit;
}

/* =========================================================
   TABLOLAR
   ========================================================= */
$db->exec("
CREATE TABLE IF NOT EXISTS ayarlar (
    ayar_key VARCHAR(100) PRIMARY KEY,
    ayar_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$db->exec("
CREATE TABLE IF NOT EXISTS kullanicilar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad_soyad VARCHAR(150) NOT NULL,
    telefon VARCHAR(20) NOT NULL UNIQUE,
    sifre_hash VARCHAR(255) NOT NULL,
    rol ENUM('admin','berber','musteri') NOT NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    olusturma_tarihi DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$db->exec("
CREATE TABLE IF NOT EXISTS hizmetler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(150) NOT NULL,
    sure_dk INT NOT NULL,
    fiyat INT NOT NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$db->exec("
CREATE TABLE IF NOT EXISTS berber_hizmetleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    berber_id INT NOT NULL,
    hizmet_id INT NOT NULL,
    UNIQUE KEY uniq_berber_hizmet (berber_id, hizmet_id),
    CONSTRAINT fk_bh_berber FOREIGN KEY (berber_id) REFERENCES kullanicilar(id) ON DELETE CASCADE,
    CONSTRAINT fk_bh_hizmet FOREIGN KEY (hizmet_id) REFERENCES hizmetler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$db->exec("
CREATE TABLE IF NOT EXISTS randevular (
    id INT AUTO_INCREMENT PRIMARY KEY,
    musteri_id INT NOT NULL,
    berber_id INT NOT NULL,
    hizmet_id INT NOT NULL,
    tarih DATE NOT NULL,
    saat TIME NOT NULL,
    durum ENUM('bekliyor','onaylandi','tamamlandi','iptal') NOT NULL DEFAULT 'bekliyor',
    not_metni TEXT NULL,
    olusturma_tarihi DATETIME NOT NULL,
    INDEX idx_berber_tarih_saat (berber_id, tarih, saat),
    CONSTRAINT fk_r_musteri FOREIGN KEY (musteri_id) REFERENCES kullanicilar(id) ON DELETE CASCADE,
    CONSTRAINT fk_r_berber FOREIGN KEY (berber_id) REFERENCES kullanicilar(id) ON DELETE CASCADE,
    CONSTRAINT fk_r_hizmet FOREIGN KEY (hizmet_id) REFERENCES hizmetler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$db->exec("
CREATE TABLE IF NOT EXISTS berber_calisma_saatleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    berber_id INT NOT NULL,
    gun_no TINYINT NOT NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    baslangic TIME NULL,
    bitis TIME NULL,
    mola_baslangic TIME NULL,
    mola_bitis TIME NULL,
    UNIQUE KEY uniq_berber_gun (berber_id, gun_no),
    CONSTRAINT fk_bcs_berber FOREIGN KEY (berber_id) REFERENCES kullanicilar(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$db->exec("
CREATE TABLE IF NOT EXISTS berber_izinleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    berber_id INT NOT NULL,
    baslangic_tarihi DATE NOT NULL,
    bitis_tarihi DATE NOT NULL,
    aciklama VARCHAR(255) NULL,
    olusturma_tarihi DATETIME NOT NULL,
    CONSTRAINT fk_bi_berber FOREIGN KEY (berber_id) REFERENCES kullanicilar(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* =========================================================
   İLK ADMIN
   ========================================================= */
$countUsers = (int)$db->query("SELECT COUNT(*) FROM kullanicilar")->fetchColumn();
if ($countUsers === 0) {
    $s = $db->prepare("
        INSERT INTO kullanicilar (ad_soyad, telefon, sifre_hash, rol, aktif, olusturma_tarihi)
        VALUES (?, ?, ?, 'admin', 1, ?)
    ");
    $s->execute([$ADMIN_NAME, $ADMIN_PHONE, password_hash($ADMIN_PASSWORD, PASSWORD_DEFAULT), nowDateTime()]);
}

/* =========================================================
   OTURUM TAZELE
   ========================================================= */
if (isset($_SESSION['user']['id'])) {
    $s = $db->prepare("SELECT id, ad_soyad, telefon, rol, aktif FROM kullanicilar WHERE id = ? LIMIT 1");
    $s->execute([$_SESSION['user']['id']]);
    $u = $s->fetch();
    if (!$u || (int)$u['aktif'] !== 1) {
        unset($_SESSION['user']);
    } else {
        $_SESSION['user'] = $u;
    }
}

/* =========================================================
   POST
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = $_POST['islem'] ?? '';

    if ($islem === 'kayit_ol') {
        $ad = trim($_POST['ad_soyad'] ?? '');
        $telefon = cleanPhone($_POST['telefon'] ?? '');
        $sifre = (string)($_POST['sifre'] ?? '');

        if ($ad === '' || strlen($telefon) < 10 || strlen($sifre) < 6) {
            flashSet('hata', 'Bilgileri eksiksiz gir. Şifre en az 6 karakter olmalı.');
            go('?sayfa=kayit');
        }

        $q = $db->prepare("SELECT COUNT(*) FROM kullanicilar WHERE telefon = ?");
        $q->execute([$telefon]);
        if ((int)$q->fetchColumn() > 0) {
            flashSet('hata', 'Bu telefon ile kayıt zaten var.');
            go('?sayfa=kayit');
        }

        $s = $db->prepare("
            INSERT INTO kullanicilar (ad_soyad, telefon, sifre_hash, rol, aktif, olusturma_tarihi)
            VALUES (?, ?, ?, 'musteri', 1, ?)
        ");
        $s->execute([$ad, $telefon, password_hash($sifre, PASSWORD_DEFAULT), nowDateTime()]);

        flashSet('basarili', 'Kayıt başarılı. Giriş yapabilirsin.');
        go('?sayfa=giris');
    }

    if ($islem === 'giris_yap') {
        $telefon = cleanPhone($_POST['telefon'] ?? '');
        $sifre = (string)($_POST['sifre'] ?? '');

        $s = $db->prepare("SELECT * FROM kullanicilar WHERE telefon = ? LIMIT 1");
        $s->execute([$telefon]);
        $u = $s->fetch();

        if (!$u || (int)$u['aktif'] !== 1 || !password_verify($sifre, $u['sifre_hash'])) {
            flashSet('hata', 'Telefon veya şifre yanlış.');
            go('?sayfa=giris');
        }

        $_SESSION['user'] = [
            'id' => $u['id'],
            'ad_soyad' => $u['ad_soyad'],
            'telefon' => $u['telefon'],
            'rol' => $u['rol'],
            'aktif' => $u['aktif'],
        ];

        flashSet('basarili', 'Giriş başarılı.');
        go('?sayfa=panel');
    }

    if ($islem === 'cikis_yap') {
        session_destroy();
        session_start();
        flashSet('basarili', 'Çıkış yapıldı.');
        go('?sayfa=giris');
    }

    if ($islem === 'sifre_degistir') {
        requireLogin();

        $eski = (string)($_POST['eski_sifre'] ?? '');
        $yeni = (string)($_POST['yeni_sifre'] ?? '');
        $yeni2 = (string)($_POST['yeni_sifre_tekrar'] ?? '');

        if (strlen($yeni) < 6) {
            flashSet('hata', 'Yeni şifre en az 6 karakter olmalı.');
            go('?sayfa=panel#ayarlar');
        }

        if ($yeni !== $yeni2) {
            flashSet('hata', 'Yeni şifreler aynı değil.');
            go('?sayfa=panel#ayarlar');
        }

        $s = $db->prepare("SELECT sifre_hash FROM kullanicilar WHERE id = ?");
        $s->execute([(int)user()['id']]);
        $hash = (string)$s->fetchColumn();

        if (!password_verify($eski, $hash)) {
            flashSet('hata', 'Mevcut şifre yanlış.');
            go('?sayfa=panel#ayarlar');
        }

        $u = $db->prepare("UPDATE kullanicilar SET sifre_hash = ? WHERE id = ?");
        $u->execute([password_hash($yeni, PASSWORD_DEFAULT), (int)user()['id']]);

        flashSet('basarili', 'Şifre değiştirildi.');
        go('?sayfa=panel#ayarlar');
    }

    if ($islem === 'logo_kaydet') {
        requireRole('admin');
        $logo = trim($_POST['logo_url'] ?? '');
        settingSet($db, 'logo_url', $logo);
        flashSet('basarili', 'Logo kaydedildi.');
        go('?sayfa=panel#ayarlar');
    }

    if ($islem === 'hizmet_ekle') {
        requireRole('admin');
        $ad = trim($_POST['ad'] ?? '');
        $sure = (int)($_POST['sure'] ?? 0);
        $fiyat = (int)($_POST['fiyat'] ?? 0);

        if ($ad === '' || $sure <= 0 || $fiyat <= 0) {
            flashSet('hata', 'Hizmet bilgileri hatalı.');
            go('?sayfa=panel#hizmetler');
        }

        $s = $db->prepare("INSERT INTO hizmetler (ad, sure_dk, fiyat, aktif) VALUES (?, ?, ?, 1)");
        $s->execute([$ad, $sure, $fiyat]);

        flashSet('basarili', 'Hizmet eklendi.');
        go('?sayfa=panel#hizmetler');
    }

    if ($islem === 'berber_ekle') {
        requireRole('admin');
        $ad = trim($_POST['ad_soyad'] ?? '');
        $telefon = cleanPhone($_POST['telefon'] ?? '');
        $sifre = (string)($_POST['sifre'] ?? '');

        if ($ad === '' || strlen($telefon) < 10 || strlen($sifre) < 6) {
            flashSet('hata', 'Berber bilgileri eksik.');
            go('?sayfa=panel#berberler');
        }

        $q = $db->prepare("SELECT COUNT(*) FROM kullanicilar WHERE telefon = ?");
        $q->execute([$telefon]);
        if ((int)$q->fetchColumn() > 0) {
            flashSet('hata', 'Bu telefon zaten kayıtlı.');
            go('?sayfa=panel#berberler');
        }

        $db->beginTransaction();
        try {
            $s = $db->prepare("
                INSERT INTO kullanicilar (ad_soyad, telefon, sifre_hash, rol, aktif, olusturma_tarihi)
                VALUES (?, ?, ?, 'berber', 1, ?)
            ");
            $s->execute([$ad, $telefon, password_hash($sifre, PASSWORD_DEFAULT), nowDateTime()]);
            $barberId = (int)$db->lastInsertId();

            $ins = $db->prepare("
                INSERT INTO berber_calisma_saatleri
                (berber_id, gun_no, aktif, baslangic, bitis, mola_baslangic, mola_bitis)
                VALUES (?, ?, 1, '09:00:00', '20:00:00', '13:00:00', '14:00:00')
            ");
            for ($g = 1; $g <= 6; $g++) {
                $ins->execute([$barberId, $g]);
            }

            $ins2 = $db->prepare("
                INSERT INTO berber_calisma_saatleri
                (berber_id, gun_no, aktif, baslangic, bitis, mola_baslangic, mola_bitis)
                VALUES (?, 7, 0, NULL, NULL, NULL, NULL)
            ");
            $ins2->execute([$barberId]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            flashSet('hata', 'Berber eklenirken hata oluştu.');
            go('?sayfa=panel#berberler');
        }

        flashSet('basarili', 'Berber eklendi. Varsayılan saatler tanımlandı.');
        go('?sayfa=panel#berberler');
    }

    if ($islem === 'calisma_saat_kaydet') {
        requireRole('admin');

        $berberId = (int)($_POST['berber_id'] ?? 0);
        $gunNo = (int)($_POST['gun_no'] ?? 0);
        $aktif = isset($_POST['aktif']) ? 1 : 0;
        $baslangic = trim($_POST['baslangic'] ?? '');
        $bitis = trim($_POST['bitis'] ?? '');
        $molaBas = trim($_POST['mola_baslangic'] ?? '');
        $molaBit = trim($_POST['mola_bitis'] ?? '');

        if ($berberId <= 0 || $gunNo < 1 || $gunNo > 7) {
            flashSet('hata', 'Geçersiz çalışma saati bilgisi.');
            go('?sayfa=panel#berberler');
        }

        if ($aktif === 1) {
            if ($baslangic === '' || $bitis === '') {
                flashSet('hata', 'Çalışan gün için başlangıç ve bitiş saati zorunlu.');
                go('?sayfa=panel&yonet_berber=' . $berberId . '#berberler');
            }

            if (strlen($baslangic) === 5) $baslangic .= ':00';
            if (strlen($bitis) === 5) $bitis .= ':00';
            if ($molaBas !== '' && strlen($molaBas) === 5) $molaBas .= ':00';
            if ($molaBit !== '' && strlen($molaBit) === 5) $molaBit .= ':00';

            if ($baslangic >= $bitis) {
                flashSet('hata', 'Başlangıç saati bitişten küçük olmalı.');
                go('?sayfa=panel&yonet_berber=' . $berberId . '#berberler');
            }

            if (($molaBas !== '' && $molaBit === '') || ($molaBas === '' && $molaBit !== '')) {
                flashSet('hata', 'Mola saatleri birlikte girilmeli.');
                go('?sayfa=panel&yonet_berber=' . $berberId . '#berberler');
            }

            if ($molaBas !== '' && $molaBit !== '') {
                if (!($molaBas >= $baslangic && $molaBit <= $bitis && $molaBas < $molaBit)) {
                    flashSet('hata', 'Mola saatleri çalışma aralığında ve doğru sırada olmalı.');
                    go('?sayfa=panel&yonet_berber=' . $berberId . '#berberler');
                }
            }
        } else {
            $baslangic = null;
            $bitis = null;
            $molaBas = null;
            $molaBit = null;
        }

        $s = $db->prepare("
            INSERT INTO berber_calisma_saatleri
            (berber_id, gun_no, aktif, baslangic, bitis, mola_baslangic, mola_bitis)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                aktif = VALUES(aktif),
                baslangic = VALUES(baslangic),
                bitis = VALUES(bitis),
                mola_baslangic = VALUES(mola_baslangic),
                mola_bitis = VALUES(mola_bitis)
        ");
        $s->execute([$berberId, $gunNo, $aktif, $baslangic, $bitis, $molaBas ?: null, $molaBit ?: null]);

        flashSet('basarili', 'Çalışma saati kaydedildi.');
        go('?sayfa=panel&yonet_berber=' . $berberId . '#berberler');
    }

    if ($islem === 'izin_ekle') {
        requireRole('admin');

        $berberId = (int)($_POST['berber_id'] ?? 0);
        $baslangic = trim($_POST['baslangic_tarihi'] ?? '');
        $bitis = trim($_POST['bitis_tarihi'] ?? '');
        $aciklama = trim($_POST['aciklama'] ?? '');

        if ($berberId <= 0 || $baslangic === '' || $bitis === '') {
            flashSet('hata', 'İzin bilgileri eksik.');
            go('?sayfa=panel#berberler');
        }

        if ($bitis < $baslangic) {
            flashSet('hata', 'Bitiş tarihi başlangıçtan küçük olamaz.');
            go('?sayfa=panel&yonet_berber=' . $berberId . '#berberler');
        }

        $s = $db->prepare("
            INSERT INTO berber_izinleri (berber_id, baslangic_tarihi, bitis_tarihi, aciklama, olusturma_tarihi)
            VALUES (?, ?, ?, ?, ?)
        ");
        $s->execute([$berberId, $baslangic, $bitis, $aciklama, nowDateTime()]);

        flashSet('basarili', 'İzin eklendi.');
        go('?sayfa=panel&yonet_berber=' . $berberId . '#berberler');
    }

    if ($islem === 'izin_sil') {
        requireRole('admin');
        $izinId = (int)($_POST['izin_id'] ?? 0);
        $berberId = (int)($_POST['berber_id'] ?? 0);

        if ($izinId > 0) {
            $s = $db->prepare("DELETE FROM berber_izinleri WHERE id = ?");
            $s->execute([$izinId]);
        }

        flashSet('basarili', 'İzin silindi.');
        go('?sayfa=panel&yonet_berber=' . $berberId . '#berberler');
    }

    if ($islem === 'berber_hizmet_kaydet') {
        requireRole('admin');

        $berberId = (int)($_POST['berber_id'] ?? 0);
        $hizmetler = $_POST['hizmetler'] ?? [];

        if ($berberId <= 0) {
            flashSet('hata', 'Geçersiz berber.');
            go('?sayfa=panel#berberler');
        }

        $db->beginTransaction();
        try {
            $del = $db->prepare("DELETE FROM berber_hizmetleri WHERE berber_id = ?");
            $del->execute([$berberId]);

            if (is_array($hizmetler)) {
                $ins = $db->prepare("INSERT INTO berber_hizmetleri (berber_id, hizmet_id) VALUES (?, ?)");
                foreach ($hizmetler as $hid) {
                    $hid = (int)$hid;
                    if ($hid > 0) {
                        $ins->execute([$berberId, $hid]);
                    }
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            flashSet('hata', 'Berber hizmetleri kaydedilemedi.');
            go('?sayfa=panel&yonet_berber=' . $berberId . '#berberler');
        }

        flashSet('basarili', 'Berbere ait hizmetler kaydedildi.');
        go('?sayfa=panel&yonet_berber=' . $berberId . '#berberler');
    }

    if ($islem === 'randevu_olustur') {
        requireRole('musteri');

        $berberId = (int)($_POST['berber_id'] ?? 0);
        $hizmetId = (int)($_POST['hizmet_id'] ?? 0);
        $tarih = trim($_POST['tarih'] ?? '');
        $saat = trim($_POST['saat'] ?? '');
        $not = trim($_POST['not_metni'] ?? '');

        if ($berberId <= 0 || $hizmetId <= 0 || $tarih === '' || $saat === '') {
            flashSet('hata', 'Berber, hizmet, tarih ve saat seçmelisin.');
            go('?sayfa=panel');
        }

        if ($tarih < nowDate()) {
            flashSet('hata', 'Geçmiş tarihe randevu oluşturamazsın.');
            go('?sayfa=panel');
        }

        if (!barberCanDoService($db, $berberId, $hizmetId)) {
            flashSet('hata', 'Seçilen berber bu hizmeti vermiyor.');
            go('?sayfa=panel&berber_id=' . $berberId . '&tarih=' . $tarih);
        }

        $available = getAvailableSlots($db, $berberId, $tarih);
        if (!in_array(substr($saat, 0, 5), $available, true)) {
            flashSet('hata', 'Seçilen saat uygun değil.');
            go('?sayfa=panel&berber_id=' . $berberId . '&tarih=' . $tarih);
        }

        if (strlen($saat) === 5) $saat .= ':00';

        $s = $db->prepare("
            INSERT INTO randevular (musteri_id, berber_id, hizmet_id, tarih, saat, durum, not_metni, olusturma_tarihi)
            VALUES (?, ?, ?, ?, ?, 'bekliyor', ?, ?)
        ");
        $s->execute([(int)user()['id'], $berberId, $hizmetId, $tarih, $saat, $not, nowDateTime()]);

        flashSet('basarili', 'Randevu oluşturuldu.');
        go('?sayfa=panel');
    }

    if ($islem === 'randevu_durum_degistir') {
        requireLogin();

        $randevuId = (int)($_POST['randevu_id'] ?? 0);
        $yeniDurum = trim($_POST['yeni_durum'] ?? '');
        $izinli = ['bekliyor','onaylandi','tamamlandi','iptal'];

        if (!in_array($yeniDurum, $izinli, true)) {
            flashSet('hata', 'Geçersiz işlem.');
            go('?sayfa=panel');
        }

        $s = $db->prepare("SELECT * FROM randevular WHERE id = ?");
        $s->execute([$randevuId]);
        $r = $s->fetch();

        if (!$r) {
            flashSet('hata', 'Randevu bulunamadı.');
            go('?sayfa=panel');
        }

        $role = user()['rol'];
        $uid = (int)user()['id'];
        $yetki = false;

        if ($role === 'admin') $yetki = true;
        if ($role === 'berber' && (int)$r['berber_id'] === $uid) $yetki = true;
        if ($role === 'musteri' && (int)$r['musteri_id'] === $uid && $yeniDurum === 'iptal') $yetki = true;

        if (!$yetki) {
            flashSet('hata', 'Bu işlem için yetkin yok.');
            go('?sayfa=panel');
        }

        $u = $db->prepare("UPDATE randevular SET durum = ? WHERE id = ?");
        $u->execute([$yeniDurum, $randevuId]);

        flashSet('basarili', 'Randevu durumu güncellendi.');
        go('?sayfa=panel');
    }
}

/* =========================================================
   VERİLER
   ========================================================= */
$sayfa = $_GET['sayfa'] ?? (isLogged() ? 'panel' : 'giris');
$flash = flashGet();
$logoUrl = settingGet($db, 'logo_url', '');

$services = $db->query("SELECT * FROM hizmetler WHERE aktif = 1 ORDER BY fiyat ASC")->fetchAll();
$barbers = $db->query("SELECT id, ad_soyad, telefon FROM kullanicilar WHERE rol='berber' AND aktif=1 ORDER BY ad_soyad")->fetchAll();

$dailyAppointments = (int)$db->query("SELECT COUNT(*) FROM randevular WHERE tarih = CURDATE() AND durum != 'iptal'")->fetchColumn();
$barberCount = (int)$db->query("SELECT COUNT(*) FROM kullanicilar WHERE rol='berber' AND aktif=1")->fetchColumn();
$customerCount = (int)$db->query("SELECT COUNT(*) FROM kullanicilar WHERE rol='musteri' AND aktif=1")->fetchColumn();
$dailyRevenue = (int)$db->query("
    SELECT COALESCE(SUM(h.fiyat),0)
    FROM randevular r
    JOIN hizmetler h ON h.id = r.hizmet_id
    WHERE r.tarih = CURDATE() AND r.durum = 'tamamlandi'
")->fetchColumn();

$todayList = $db->query("
    SELECT r.*, 
           m.ad_soyad AS musteri_adi, m.telefon AS musteri_telefon,
           b.ad_soyad AS berber_adi, b.telefon AS berber_telefon,
           h.ad AS hizmet_adi, h.fiyat
    FROM randevular r
    JOIN kullanicilar m ON m.id = r.musteri_id
    JOIN kullanicilar b ON b.id = r.berber_id
    JOIN hizmetler h ON h.id = r.hizmet_id
    WHERE r.tarih = CURDATE()
    ORDER BY r.saat ASC
")->fetchAll();

$weekRevenue = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $s = $db->prepare("
        SELECT COALESCE(SUM(h.fiyat),0)
        FROM randevular r
        JOIN hizmetler h ON h.id = r.hizmet_id
        WHERE r.tarih = ? AND r.durum = 'tamamlandi'
    ");
    $s->execute([$d]);
    $weekRevenue[] = ['etiket' => date('d.m', strtotime($d)), 'deger' => (int)$s->fetchColumn()];
}

$myAppointments = [];
if (isLogged()) {
    if (user()['rol'] === 'musteri') {
        $s = $db->prepare("
            SELECT r.*, b.ad_soyad AS berber_adi, b.telefon AS berber_telefon, h.ad AS hizmet_adi, h.fiyat
            FROM randevular r
            JOIN kullanicilar b ON b.id = r.berber_id
            JOIN hizmetler h ON h.id = r.hizmet_id
            WHERE r.musteri_id = ?
            ORDER BY r.tarih DESC, r.saat DESC
        ");
        $s->execute([(int)user()['id']]);
        $myAppointments = $s->fetchAll();
    }

    if (user()['rol'] === 'berber') {
        $s = $db->prepare("
            SELECT r.*, m.ad_soyad AS musteri_adi, m.telefon AS musteri_telefon, h.ad AS hizmet_adi, h.fiyat
            FROM randevular r
            JOIN kullanicilar m ON m.id = r.musteri_id
            JOIN hizmetler h ON h.id = r.hizmet_id
            WHERE r.berber_id = ?
            ORDER BY r.tarih DESC, r.saat DESC
        ");
        $s->execute([(int)user()['id']]);
        $myAppointments = $s->fetchAll();
    }
}

$barberPerf = $db->query("
    SELECT k.id, k.ad_soyad,
           COUNT(r.id) AS toplam_is,
           COALESCE(SUM(CASE WHEN r.durum='tamamlandi' THEN h.fiyat ELSE 0 END),0) AS toplam_tutar
    FROM kullanicilar k
    LEFT JOIN randevular r ON r.berber_id = k.id
    LEFT JOIN hizmetler h ON h.id = r.hizmet_id
    WHERE k.rol='berber' AND k.aktif=1
    GROUP BY k.id
    ORDER BY toplam_tutar DESC, toplam_is DESC
")->fetchAll();

$allUsers = [];
if (isLogged() && user()['rol'] === 'admin') {
    $allUsers = $db->query("
        SELECT id, ad_soyad, telefon, rol, aktif, olusturma_tarihi
        FROM kullanicilar
        ORDER BY rol, ad_soyad
    ")->fetchAll();
}

/* müşteri randevu seçimi */
$selectedBarber = 0;
$selectedDate = nowDate();
$visibleSlots = [];
$selectedBarberLeave = false;
$selectedBarberClosed = false;
$customerVisibleServices = [];

if (isLogged() && user()['rol'] === 'musteri') {
    $selectedBarber = isset($_GET['berber_id']) ? (int)$_GET['berber_id'] : 0;
    $selectedDate = $_GET['tarih'] ?? nowDate();
    if ($selectedDate < nowDate()) $selectedDate = nowDate();

    if ($selectedBarber > 0) {
        $selectedBarberLeave = barberOnLeave($db, $selectedBarber, $selectedDate);
        $work = getBarberWorkRow($db, $selectedBarber, tarihGunNo($selectedDate));
        $selectedBarberClosed = !$work || (int)$work['aktif'] !== 1 || empty($work['baslangic']) || empty($work['bitis']);
        $serviceIds = getBarberServiceIds($db, $selectedBarber);

        if (!empty($serviceIds)) {
            $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
            $s = $db->prepare("SELECT * FROM hizmetler WHERE aktif = 1 AND id IN ($placeholders) ORDER BY fiyat ASC");
            $s->execute($serviceIds);
            $customerVisibleServices = $s->fetchAll();
        }

        if (!$selectedBarberLeave && !$selectedBarberClosed) {
            $visibleSlots = getAllVisibleSlots($db, $selectedBarber, $selectedDate);
        }
    }
}

/* admin berber yönetimi */
$yonetBerberId = (isset($_GET['yonet_berber']) && isLogged() && user()['rol'] === 'admin') ? (int)$_GET['yonet_berber'] : 0;
$yonetBerber = null;
$yonetBerberSaatler = [];
$yonetBerberIzinler = [];
$yonetBerberHizmetIds = [];
if ($yonetBerberId > 0) {
    $s = $db->prepare("SELECT id, ad_soyad, telefon FROM kullanicilar WHERE id = ? AND rol='berber' LIMIT 1");
    $s->execute([$yonetBerberId]);
    $yonetBerber = $s->fetch();

    if ($yonetBerber) {
        $s2 = $db->prepare("SELECT * FROM berber_calisma_saatleri WHERE berber_id = ? ORDER BY gun_no");
        $s2->execute([$yonetBerberId]);
        foreach ($s2->fetchAll() as $row) {
            $yonetBerberSaatler[(int)$row['gun_no']] = $row;
        }

        $s3 = $db->prepare("SELECT * FROM berber_izinleri WHERE berber_id = ? ORDER BY baslangic_tarihi DESC");
        $s3->execute([$yonetBerberId]);
        $yonetBerberIzinler = $s3->fetchAll();

        $yonetBerberHizmetIds = getBarberServiceIds($db, $yonetBerberId);
    }
}

$qrLink = baseUrl() . '?sayfa=giris';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($APP_NAME) ?></title>
<meta name="theme-color" content="#d4af37">
<link rel="manifest" href="<?= e(baseUrl()) ?>?manifest=1">
<link rel="apple-touch-icon" href="<?= e(baseUrl()) ?>?icon=192">
<style>
:root{
  --arka:#0c0a0a;--panel:#151111;--altin:#d4af37;--altin2:#f0d277;--yazi:#f8f2e8;--soluk:#c9bba2;
  --kenar:rgba(212,175,55,.15);--golge:0 18px 50px rgba(0,0,0,.35);--r:24px;
}
*{box-sizing:border-box} html,body{margin:0;padding:0;color:var(--yazi);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:radial-gradient(circle at top right, rgba(212,175,55,.12), transparent 25%),radial-gradient(circle at bottom left, rgba(212,175,55,.08), transparent 20%),linear-gradient(180deg,#19100c 0%, #0b0909 100%)}
a{text-decoration:none;color:inherit} button,input,select,textarea{font:inherit}
.wrap{min-height:100vh;display:flex}
.sol{width:260px;padding:22px;background:linear-gradient(180deg, rgba(22,18,18,.97), rgba(10,9,9,.98));border-right:1px solid var(--kenar);position:sticky;top:0;height:100vh}
.marka{display:flex;gap:14px;align-items:center;padding:14px;border-radius:18px;border:1px solid var(--kenar);background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01));box-shadow:var(--golge)}
.logo{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;background:radial-gradient(circle at 30% 30%, #3a2811, #130f0d);color:var(--altin2);font-weight:800;overflow:hidden}
.logo img{width:100%;height:100%;object-fit:cover}
.marka h1{margin:0;font-size:15px;line-height:1.2;color:var(--altin2)}
.marka p{margin:2px 0 0;font-size:11px;color:var(--soluk)}
.menu{display:grid;gap:8px;margin-top:22px}
.menu a{padding:14px 16px;border-radius:14px;display:block;border:1px solid transparent;color:#efe5d3}
.menu a:hover,.menu a.aktif{background:rgba(212,175,55,.08);border-color:var(--kenar)}
.alt{position:absolute;left:22px;right:22px;bottom:22px}
.ana{flex:1;padding:24px}
.ust{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:18px}
.ust h2{margin:0;font-size:34px}
.kutu{background:linear-gradient(180deg, rgba(24,19,19,.94), rgba(16,13,13,.96));border:1px solid var(--kenar);border-radius:var(--r);padding:20px;box-shadow:var(--golge)}
.grid{display:grid;gap:18px}.dortlu{grid-template-columns:repeat(4,minmax(0,1fr))}
.metrik{display:flex;justify-content:space-between;align-items:center;gap:12px}
.metrik small{display:block;color:var(--soluk);margin-bottom:6px}.metrik strong{font-size:38px;line-height:1;color:var(--altin2)}
.ikon{width:54px;height:54px;border-radius:18px;display:grid;place-items:center;background:rgba(212,175,55,.08);border:1px solid var(--kenar);color:var(--altin2);font-size:22px}
.iki{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-top:18px}
.baslik{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}
.baslik h3{margin:0;font-size:28px}.soluk{color:var(--soluk);font-size:13px}
.grafik{height:220px;padding:14px 8px 6px 8px;border-radius:18px;background:linear-gradient(to top, rgba(212,175,55,.06) 1px, transparent 1px) 0 0/100% 20%,linear-gradient(to right, rgba(212,175,55,.04) 1px, transparent 1px) 0 0/12.5% 100%}
.liste{display:grid;gap:10px}
.satir{display:grid;gap:12px;align-items:center;grid-template-columns:64px 1fr auto auto;padding:14px;border-radius:16px;border:1px solid rgba(212,175,55,.10);background:rgba(255,255,255,.02)}
.minik{width:48px;height:48px;border-radius:50%;display:grid;place-items:center;font-weight:800;background:linear-gradient(180deg,#6a4b21,#291f14)}
.rozet{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;font-size:12px;font-weight:700}
.rozet.bekliyor{background:rgba(255,195,0,.12);color:#ffda70;border:1px solid rgba(255,195,0,.2)}
.rozet.onaylandi{background:rgba(85,170,255,.12);color:#9ad4ff;border:1px solid rgba(85,170,255,.2)}
.rozet.tamamlandi{background:rgba(122,226,143,.10);color:#9af2ab;border:1px solid rgba(122,226,143,.2)}
.rozet.iptal{background:rgba(255,119,119,.12);color:#ffb0b0;border:1px solid rgba(255,119,119,.2)}
.form-grid{display:grid;gap:12px} label{display:block;margin-bottom:6px;font-size:13px;color:var(--soluk)}
input,select,textarea{width:100%;padding:14px;border-radius:14px;border:1px solid var(--kenar);background:#120f0f;color:#fff;outline:none} textarea{min-height:92px;resize:vertical}
.buton{border:none;cursor:pointer;border-radius:14px;padding:12px 16px;font-weight:700;display:inline-block}
.altin{background:linear-gradient(180deg, #f1d17a, #c89a2c);color:#1b1308;box-shadow:0 10px 30px rgba(212,175,55,.25)}
.koyu{background:#1f1919;color:#f3eadc;border:1px solid var(--kenar)}
.kirmizi{background:#5d1f25;color:#fff;border:1px solid rgba(255,119,119,.18)}
.yesil{background:#1c5f3b;color:#fff;border:1px solid rgba(122,226,143,.18)}
.islemler{display:flex;gap:10px;flex-wrap:wrap}
.mesaj{margin-bottom:18px;padding:14px 16px;border-radius:16px;font-weight:700}
.mesaj.basarili{background:rgba(122,226,143,.12);color:#b0f5bc;border:1px solid rgba(122,226,143,.18)}
.mesaj.hata{background:rgba(255,119,119,.12);color:#ffb0b0;border:1px solid rgba(255,119,119,.18)}
.giris-wrap{min-height:100vh;display:grid;place-items:center;padding:24px}
.giris-kutu{width:min(100%,480px);padding:24px;border-radius:28px;background:linear-gradient(180deg, rgba(20,16,16,.96), rgba(12,10,10,.98));border:1px solid var(--kenar);box-shadow:var(--golge)}
.giris-bas{text-align:center;margin-bottom:18px}.giris-bas h2{margin:10px 0 6px;font-size:32px;color:var(--altin2)}.giris-bas p{margin:0;color:var(--soluk)}
.sekmeler{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.sekme{padding:10px 14px;border-radius:999px;border:1px solid var(--kenar);background:#151111;color:#f3eadc;font-weight:700}
.sekme.aktif{background:rgba(212,175,55,.12)}
.tablo{width:100%;border-collapse:collapse}.tablo th,.tablo td{text-align:left;padding:12px;border-bottom:1px solid rgba(212,175,55,.1);vertical-align:top}.tablo th{color:var(--altin2);font-size:13px}
.iki-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:18px}
.kv{display:grid;gap:10px}.kv .cizgi{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px dashed rgba(212,175,55,.12)}.kv .cizgi:last-child{border-bottom:none}
.qr-alan{display:grid;grid-template-columns:230px 1fr;gap:18px;align-items:center}
.qr-hedef{width:210px;height:210px;border-radius:24px;background:#fff;padding:14px}
.kucuk{font-size:12px;color:var(--soluk)}
.saat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:10px}
.saat-btn{padding:12px;border-radius:12px;border:1px solid rgba(212,175,55,.15);font-weight:700;cursor:pointer;transition:.2s ease}
.saat-btn.bos{background:#1c5f3b;color:#fff}
.saat-btn.dolu{background:#5d1f25;color:#fff;cursor:not-allowed;opacity:.8}
.saat-btn.mola{background:#604b14;color:#fff;cursor:not-allowed;opacity:.85}
.saat-btn.secili{outline:2px solid #f0d277;background:#c89a2c;color:#1b1308}
.kur{margin-top:12px;padding:12px;border:1px dashed var(--kenar);border-radius:14px;color:var(--soluk)}
.secili-kutu{border:1px solid var(--kenar);padding:14px;border-radius:16px;background:rgba(255,255,255,.02)}
.checkbox-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.checkbox-item{padding:10px 12px;border:1px solid var(--kenar);border-radius:12px;background:rgba(255,255,255,.02)}
.checkbox-item label{margin:0;display:flex;align-items:center;gap:8px;color:var(--yazi)}
@media (max-width:1200px){.dortlu{grid-template-columns:repeat(2,minmax(0,1fr))}.iki{grid-template-columns:1fr}}
@media (max-width:900px){.wrap{display:block}.sol{width:auto;height:auto;position:relative}.ana{padding:16px}.iki-form,.dortlu,.qr-alan,.saat-grid,.checkbox-list{grid-template-columns:1fr}.satir{grid-template-columns:56px 1fr}.satir>:nth-child(3),.satir>:nth-child(4){grid-column:2}.ust{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body>

<?php if ($sayfa === 'giris' || $sayfa === 'kayit'): ?>
<div class="giris-wrap">
  <div class="giris-kutu">
    <div class="giris-bas">
      <div class="logo" style="margin:0 auto">
        <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="Logo"><?php else: ?>AT<?php endif; ?>
      </div>
      <h2><?= e($APP_NAME) ?></h2>
      <p>Premium randevu ve yönetim sistemi</p>
    </div>

    <?php if ($flash): ?>
      <div class="mesaj <?= e($flash['type']) ?>"><?= e($flash['text']) ?></div>
    <?php endif; ?>

    <div class="sekmeler">
      <a class="sekme <?= $sayfa === 'giris' ? 'aktif' : '' ?>" href="?sayfa=giris">Giriş Yap</a>
      <a class="sekme <?= $sayfa === 'kayit' ? 'aktif' : '' ?>" href="?sayfa=kayit">Kayıt Ol</a>
    </div>

    <?php if ($sayfa === 'giris'): ?>
      <form method="post" class="form-grid">
        <input type="hidden" name="islem" value="giris_yap">
        <div>
          <label>Telefon</label>
          <input name="telefon" placeholder="05XXXXXXXXX" required>
        </div>
        <div>
          <label>Şifre</label>
          <input type="password" name="sifre" placeholder="******" required>
        </div>
        <button class="buton altin" type="submit">Giriş Yap</button>
      </form>
      <div class="kur">
        iPhone: Safari → Paylaş → <b>Ana Ekrana Ekle</b><br>
        Android: Chrome → Menü → <b>Ana ekrana ekle</b>
      </div>
    <?php else: ?>
      <form method="post" class="form-grid">
        <input type="hidden" name="islem" value="kayit_ol">
        <div>
          <label>Ad Soyad</label>
          <input name="ad_soyad" required>
        </div>
        <div>
          <label>Telefon</label>
          <input name="telefon" placeholder="05XXXXXXXXX" required>
        </div>
        <div>
          <label>Şifre</label>
          <input type="password" name="sifre" minlength="6" required>
        </div>
        <button class="buton altin" type="submit">Kayıt Ol</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php else: requireLogin(); ?>
<div class="wrap">
  <aside class="sol">
    <div class="marka">
      <div class="logo">
        <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="Logo"><?php else: ?>AT<?php endif; ?>
      </div>
      <div>
        <h1><?= e($APP_NAME) ?></h1>
        <p>FULL SİSTEM</p>
      </div>
    </div>

    <nav class="menu">
      <a class="aktif" href="?sayfa=panel">Anasayfa</a>
      <?php if (user()['rol'] === 'admin'): ?>
        <a href="#admin-randevular">Randevular</a>
        <a href="#berberler">Berberler</a>
        <a href="#musteriler">Müşteriler</a>
        <a href="#hizmetler">Hizmetler</a>
        <a href="#qr">QR Kod</a>
        <a href="#ayarlar">Ayarlar</a>
      <?php elseif (user()['rol'] === 'berber'): ?>
        <a href="#berber-randevular">Randevularım</a>
        <a href="#ayarlar">Ayarlar</a>
      <?php else: ?>
        <a href="#musteri-randevu">Randevu Al</a>
        <a href="#musteri-randevular">Randevularım</a>
        <a href="#ayarlar">Ayarlar</a>
      <?php endif; ?>
    </nav>

    <div class="alt">
      <form method="post">
        <input type="hidden" name="islem" value="cikis_yap">
        <button class="buton koyu" type="submit" style="width:100%">Çıkış Yap</button>
      </form>
    </div>
  </aside>

  <main class="ana">
    <div class="ust">
      <div>
        <div class="kucuk">Hoş geldin</div>
        <h2><?= e(user()['ad_soyad']) ?></h2>
      </div>
      <div class="kutu" style="padding:12px 16px">
        <b><?= e(user()['ad_soyad']) ?></b><br>
        <span class="kucuk"><?= e(roleText(user()['rol'])) ?></span>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="mesaj <?= e($flash['type']) ?>"><?= e($flash['text']) ?></div>
    <?php endif; ?>

    <?php if (user()['rol'] === 'admin'): ?>

      <section class="grid dortlu">
        <div class="kutu"><div class="metrik"><div><small>Günlük Randevu</small><strong><?= $dailyAppointments ?></strong></div><div class="ikon">🗓</div></div></div>
        <div class="kutu"><div class="metrik"><div><small>Günlük Ciro</small><strong><?= number_format($dailyRevenue,0,',','.') ?> TL</strong></div><div class="ikon">₺</div></div></div>
        <div class="kutu"><div class="metrik"><div><small>Berber Sayısı</small><strong><?= $barberCount ?></strong></div><div class="ikon">✂</div></div></div>
        <div class="kutu"><div class="metrik"><div><small>Toplam Müşteri</small><strong><?= $customerCount ?></strong></div><div class="ikon">👤</div></div></div>
      </section>

      <section class="iki">
        <div class="kutu">
          <div class="baslik"><h3>Gelir Grafiği</h3><div class="soluk">Son 7 gün</div></div>
          <div class="grafik">
            <?php
            $vals = array_column($weekRevenue, 'deger');
            $maxv = max(1, max($vals));
            $pts = [];
            foreach ($weekRevenue as $i => $rw) {
                $x = 30 + ($i * (600 / max(1, count($weekRevenue)-1)));
                $y = 170 - (($rw['deger'] / $maxv) * 140);
                $pts[] = round($x,2) . ',' . round($y,2);
            }
            ?>
            <svg viewBox="0 0 660 190" preserveAspectRatio="none" style="width:100%;height:100%">
              <polyline points="<?= e(implode(' ', $pts)) ?>" fill="none" stroke="#d4af37" stroke-width="3"></polyline>
              <?php foreach ($weekRevenue as $i => $rw):
                $x = 30 + ($i * (600 / max(1, count($weekRevenue)-1)));
                $y = 170 - (($rw['deger'] / $maxv) * 140);
              ?>
              <circle cx="<?= $x ?>" cy="<?= $y ?>" r="4.5" fill="#f1d17a"></circle>
              <text x="<?= $x - 12 ?>" y="186" fill="#c9bba2" font-size="10"><?= e($rw['etiket']) ?></text>
              <?php endforeach; ?>
            </svg>
          </div>
        </div>

        <div class="kutu">
          <div class="baslik"><h3>Bugünkü Ciro</h3><div class="soluk"><?= number_format($dailyRevenue,0,',','.') ?> TL</div></div>
          <div class="kv">
            <?php
            $dailyServices = $db->query("
              SELECT h.ad, COUNT(r.id) AS adet,
                     COALESCE(SUM(CASE WHEN r.durum='tamamlandi' THEN h.fiyat ELSE 0 END),0) AS gelir
              FROM hizmetler h
              LEFT JOIN randevular r
                ON r.hizmet_id = h.id
               AND r.tarih = CURDATE()
               AND r.durum != 'iptal'
              WHERE h.aktif = 1
              GROUP BY h.id
              ORDER BY gelir DESC, adet DESC
            ")->fetchAll();
            foreach ($dailyServices as $ds): ?>
              <div class="cizgi">
                <span><?= e($ds['ad']) ?></span>
                <strong><?= (int)$ds['adet'] ?> iş / <?= number_format((int)$ds['gelir'],0,',','.') ?> TL</strong>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section id="admin-randevular" class="kutu" style="margin-top:18px">
        <div class="baslik"><h3>Bugünkü İş Listesi</h3><div class="soluk"><?= nowDate() ?></div></div>
        <div class="liste">
          <?php if (!$todayList): ?>
            <div class="kucuk">Bugün için randevu yok.</div>
          <?php else: foreach ($todayList as $r): 
            $waText = "Merhaba {$r['musteri_adi']}, {$r['tarih']} tarihinde saat " . substr((string)$r['saat'],0,5) . " için {$APP_NAME} randevunuz bulunmaktadır. Hizmet: {$r['hizmet_adi']}.";
            $waLink = createWhatsAppLink((string)$r['musteri_telefon'], $waText);
          ?>
            <div class="satir">
              <div class="minik"><?= mb_substr(e($r['musteri_adi']),0,1) ?></div>
              <div>
                <div style="font-weight:800"><?= e($r['musteri_adi']) ?></div>
                <div class="kucuk"><?= e($r['hizmet_adi']) ?> · <?= e($r['berber_adi']) ?></div>
              </div>
              <div style="font-weight:800"><?= e(substr((string)$r['saat'],0,5)) ?></div>
              <div class="islemler">
                <span class="rozet <?= e($r['durum']) ?>"><?= e(statusText($r['durum'])) ?></span>
                <a class="buton yesil" href="<?= e($waLink) ?>" target="_blank">WhatsApp</a>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </section>

      <section class="iki" style="margin-top:18px">
        <div class="kutu" id="berberler">
          <div class="baslik"><h3>Berberler</h3><div class="soluk">Yönetim</div></div>
          <div class="liste">
            <?php if (!$barberPerf): ?>
              <div class="kucuk">Henüz berber eklenmemiş.</div>
            <?php else: foreach ($barberPerf as $bp): ?>
              <div class="satir">
                <div class="minik"><?= mb_substr(e($bp['ad_soyad']),0,1) ?></div>
                <div>
                  <div style="font-weight:800"><?= e($bp['ad_soyad']) ?></div>
                  <div class="kucuk"><?= (int)$bp['toplam_is'] ?> iş</div>
                </div>
                <div style="font-weight:800"><?= number_format((int)$bp['toplam_tutar'],0,',','.') ?> TL</div>
                <div class="islemler">
                  <a class="buton koyu" href="?sayfa=panel&yonet_berber=<?= (int)$bp['id'] ?>#berberler">Yönet</a>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <div class="baslik" style="margin-top:18px"><h3>Yeni Berber Ekle</h3><div class="soluk">Personel tanımla</div></div>
          <form method="post" class="form-grid">
            <input type="hidden" name="islem" value="berber_ekle">
            <div><label>Ad Soyad</label><input name="ad_soyad" required></div>
            <div><label>Telefon</label><input name="telefon" required></div>
            <div><label>Şifre</label><input type="password" name="sifre" minlength="6" required></div>
            <div class="islemler"><button class="buton altin" type="submit">Berber Ekle</button></div>
          </form>

          <?php if ($yonetBerber): ?>
            <div class="secili-kutu" style="margin-top:18px">
              <div class="baslik">
                <h3><?= e($yonetBerber['ad_soyad']) ?> · Çalışma Saatleri</h3>
                <div class="soluk">Günlük saat, mola, izin</div>
              </div>

              <?php for ($g = 1; $g <= 7; $g++): 
                $row = $yonetBerberSaatler[$g] ?? null;
              ?>
                <form method="post" class="form-grid" style="margin-bottom:18px;padding-bottom:18px;border-bottom:1px dashed rgba(212,175,55,.12)">
                  <input type="hidden" name="islem" value="calisma_saat_kaydet">
                  <input type="hidden" name="berber_id" value="<?= (int)$yonetBerber['id'] ?>">
                  <input type="hidden" name="gun_no" value="<?= $g ?>">

                  <div class="baslik" style="margin-bottom:4px">
                    <h3 style="font-size:18px"><?= e(gunText($g)) ?></h3>
                    <label style="display:flex;align-items:center;gap:8px;margin:0;color:var(--yazi)">
                      <input type="checkbox" name="aktif" value="1" <?= (!$row || (int)$row['aktif'] === 1) ? 'checked' : '' ?> style="width:auto">
                      Çalışıyor
                    </label>
                  </div>

                  <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px">
                    <div>
                      <label>Başlangıç</label>
                      <input type="time" name="baslangic" value="<?= e($row && $row['baslangic'] ? substr((string)$row['baslangic'],0,5) : '09:00') ?>">
                    </div>
                    <div>
                      <label>Bitiş</label>
                      <input type="time" name="bitis" value="<?= e($row && $row['bitis'] ? substr((string)$row['bitis'],0,5) : '20:00') ?>">
                    </div>
                    <div>
                      <label>Mola Başlangıç</label>
                      <input type="time" name="mola_baslangic" value="<?= e($row && $row['mola_baslangic'] ? substr((string)$row['mola_baslangic'],0,5) : '13:00') ?>">
                    </div>
                    <div>
                      <label>Mola Bitiş</label>
                      <input type="time" name="mola_bitis" value="<?= e($row && $row['mola_bitis'] ? substr((string)$row['mola_bitis'],0,5) : '14:00') ?>">
                    </div>
                  </div>

                  <div class="islemler">
                    <button class="buton altin" type="submit">Kaydet</button>
                  </div>
                </form>
              <?php endfor; ?>

              <div class="baslik"><h3><?= e($yonetBerber['ad_soyad']) ?> · Verdiği Hizmetler</h3><div class="soluk">Sadece seçilen hizmetler müşteriye görünür</div></div>
              <form method="post" class="form-grid" style="margin-bottom:18px">
                <input type="hidden" name="islem" value="berber_hizmet_kaydet">
                <input type="hidden" name="berber_id" value="<?= (int)$yonetBerber['id'] ?>">
                <div class="checkbox-list">
                  <?php foreach ($services as $srv): ?>
                    <div class="checkbox-item">
                      <label>
                        <input type="checkbox" name="hizmetler[]" value="<?= (int)$srv['id'] ?>" <?= in_array((int)$srv['id'], $yonetBerberHizmetIds, true) ? 'checked' : '' ?> style="width:auto">
                        <?= e($srv['ad']) ?> · <?= number_format((int)$srv['fiyat'],0,',','.') ?> TL
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="islemler">
                  <button class="buton altin" type="submit">Hizmetleri Kaydet</button>
                </div>
              </form>

              <div class="baslik"><h3><?= e($yonetBerber['ad_soyad']) ?> · İzin Günleri</h3><div class="soluk">Tarih aralığı tanımla</div></div>

              <form method="post" class="form-grid">
                <input type="hidden" name="islem" value="izin_ekle">
                <input type="hidden" name="berber_id" value="<?= (int)$yonetBerber['id'] ?>">
                <div>
                  <label>Başlangıç Tarihi</label>
                  <input type="date" name="baslangic_tarihi" required>
                </div>
                <div>
                  <label>Bitiş Tarihi</label>
                  <input type="date" name="bitis_tarihi" required>
                </div>
                <div>
                  <label>Açıklama</label>
                  <input name="aciklama" placeholder="Yıllık izin / özel izin">
                </div>
                <div class="islemler">
                  <button class="buton altin" type="submit">İzin Ekle</button>
                </div>
              </form>

              <table class="tablo" style="margin-top:12px">
                <thead>
                  <tr>
                    <th>Başlangıç</th>
                    <th>Bitiş</th>
                    <th>Açıklama</th>
                    <th>İşlem</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$yonetBerberIzinler): ?>
                    <tr><td colspan="4">Tanımlı izin yok.</td></tr>
                  <?php else: foreach ($yonetBerberIzinler as $iz): ?>
                    <tr>
                      <td><?= e($iz['baslangic_tarihi']) ?></td>
                      <td><?= e($iz['bitis_tarihi']) ?></td>
                      <td><?= e($iz['aciklama']) ?></td>
                      <td>
                        <form method="post">
                          <input type="hidden" name="islem" value="izin_sil">
                          <input type="hidden" name="izin_id" value="<?= (int)$iz['id'] ?>">
                          <input type="hidden" name="berber_id" value="<?= (int)$yonetBerber['id'] ?>">
                          <button class="buton kirmizi" type="submit">Sil</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="kutu" id="hizmetler">
          <div class="baslik"><h3>Hizmetler</h3><div class="soluk">Fiyat ve süre</div></div>
          <div class="kv">
            <?php foreach ($services as $h): ?>
              <div class="cizgi">
                <span><?= e($h['ad']) ?></span>
                <strong><?= (int)$h['sure_dk'] ?> dk · <?= number_format((int)$h['fiyat'],0,',','.') ?> TL</strong>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="baslik" style="margin-top:18px"><h3>Yeni Hizmet</h3><div class="soluk">Servis ekle</div></div>
          <form method="post" class="form-grid">
            <input type="hidden" name="islem" value="hizmet_ekle">
            <div><label>Hizmet Adı</label><input name="ad" required></div>
            <div><label>Süre (dk)</label><input type="number" name="sure" min="5" required></div>
            <div><label>Fiyat</label><input type="number" name="fiyat" min="1" required></div>
            <div class="islemler"><button class="buton altin" type="submit">Hizmet Ekle</button></div>
          </form>
        </div>
      </section>

      <section class="kutu" id="musteriler" style="margin-top:18px">
        <div class="baslik"><h3>Tüm Kullanıcılar</h3><div class="soluk">Yönetici · Berber · Müşteri</div></div>
        <table class="tablo">
          <thead><tr><th>Ad Soyad</th><th>Telefon</th><th>Rol</th><th>Durum</th><th>Kayıt Tarihi</th></tr></thead>
          <tbody>
            <?php foreach ($allUsers as $u): ?>
              <tr>
                <td><?= e($u['ad_soyad']) ?></td>
                <td><?= e($u['telefon']) ?></td>
                <td><?= e(roleText($u['rol'])) ?></td>
                <td><?= (int)$u['aktif'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                <td><?= e(substr((string)$u['olusturma_tarihi'],0,10)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>

      <section class="iki-form">
        <div class="kutu" id="qr">
          <div class="baslik"><h3>QR Kod</h3><div class="soluk">Müşteri direkt girişe gelsin</div></div>
          <div class="qr-alan">
            <div id="qrcode" class="qr-hedef"></div>
            <div>
              <div style="font-size:28px;font-weight:800;color:var(--altin2)"><?= e($APP_NAME) ?></div>
              <p class="kucuk">Bu QR kodu tarayan müşteri giriş veya kayıt ekranına gelir.</p>
              <div class="kutu" style="padding:14px;margin-top:10px">
                <div class="kucuk">QR Link</div>
                <div style="word-break:break-all"><?= e($qrLink) ?></div>
              </div>
            </div>
          </div>
        </div>

        <div class="kutu" id="ayarlar">
          <div class="baslik"><h3>Ayarlar</h3><div class="soluk">Logo ve hesap</div></div>
          <div class="kv">
            <div class="cizgi"><span>Ad Soyad</span><strong><?= e(user()['ad_soyad']) ?></strong></div>
            <div class="cizgi"><span>Telefon</span><strong><?= e(user()['telefon']) ?></strong></div>
            <div class="cizgi"><span>Rol</span><strong><?= e(roleText(user()['rol'])) ?></strong></div>
          </div>

          <form method="post" class="form-grid" style="margin-top:18px">
            <input type="hidden" name="islem" value="logo_kaydet">
            <div>
              <label>Logo URL</label>
              <input name="logo_url" value="<?= e($logoUrl) ?>" placeholder="https://.../logo.png">
            </div>
            <div class="islemler">
              <button class="buton altin" type="submit">Logoyu Kaydet</button>
            </div>
          </form>

          <form method="post" class="form-grid" style="margin-top:18px">
            <input type="hidden" name="islem" value="sifre_degistir">
            <div><label>Mevcut Şifre</label><input type="password" name="eski_sifre" required></div>
            <div><label>Yeni Şifre</label><input type="password" name="yeni_sifre" minlength="6" required></div>
            <div><label>Yeni Şifre Tekrar</label><input type="password" name="yeni_sifre_tekrar" minlength="6" required></div>
            <div class="islemler"><button class="buton koyu" type="submit">Şifre Değiştir</button></div>
          </form>
        </div>
      </section>

    <?php elseif (user()['rol'] === 'berber'): ?>

      <section id="berber-randevular" class="kutu">
        <div class="baslik"><h3>Berber Paneli</h3><div class="soluk">Sadece kendi randevuların</div></div>
        <div class="liste">
          <?php if (!$myAppointments): ?>
            <div class="kucuk">Henüz randevu yok.</div>
          <?php else: foreach ($myAppointments as $r):
            $waText = "Merhaba {$r['musteri_adi']}, {$r['tarih']} tarihinde saat " . substr((string)$r['saat'],0,5) . " için {$APP_NAME} randevunuz bulunmaktadır. Hizmet: {$r['hizmet_adi']}.";
            $waLink = createWhatsAppLink((string)$r['musteri_telefon'], $waText);
          ?>
            <div class="satir">
              <div class="minik"><?= mb_substr(e($r['musteri_adi']),0,1) ?></div>
              <div>
                <div style="font-weight:800"><?= e($r['musteri_adi']) ?></div>
                <div class="kucuk"><?= e($r['tarih']) ?> · <?= e(substr((string)$r['saat'],0,5)) ?> · <?= e($r['hizmet_adi']) ?></div>
              </div>
              <div class="islemler">
                <span class="rozet <?= e($r['durum']) ?>"><?= e(statusText($r['durum'])) ?></span>
                <a class="buton yesil" href="<?= e($waLink) ?>" target="_blank">WhatsApp</a>
              </div>
              <div class="islemler">
                <?php if ($r['durum'] !== 'onaylandi'): ?>
                  <form method="post">
                    <input type="hidden" name="islem" value="randevu_durum_degistir">
                    <input type="hidden" name="randevu_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="yeni_durum" value="onaylandi">
                    <button class="buton koyu" type="submit">Onayla</button>
                  </form>
                <?php endif; ?>
                <?php if ($r['durum'] !== 'tamamlandi'): ?>
                  <form method="post">
                    <input type="hidden" name="islem" value="randevu_durum_degistir">
                    <input type="hidden" name="randevu_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="yeni_durum" value="tamamlandi">
                    <button class="buton altin" type="submit">Tamamla</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </section>

      <section class="kutu" id="ayarlar" style="margin-top:18px">
        <div class="baslik"><h3>Kullanıcı Bilgisi</h3><div class="soluk">Aktif oturum</div></div>
        <div class="kv">
          <div class="cizgi"><span>Ad Soyad</span><strong><?= e(user()['ad_soyad']) ?></strong></div>
          <div class="cizgi"><span>Telefon</span><strong><?= e(user()['telefon']) ?></strong></div>
          <div class="cizgi"><span>Rol</span><strong><?= e(roleText(user()['rol'])) ?></strong></div>
        </div>

        <form method="post" class="form-grid" style="margin-top:18px">
          <input type="hidden" name="islem" value="sifre_degistir">
          <div><label>Mevcut Şifre</label><input type="password" name="eski_sifre" required></div>
          <div><label>Yeni Şifre</label><input type="password" name="yeni_sifre" minlength="6" required></div>
          <div><label>Yeni Şifre Tekrar</label><input type="password" name="yeni_sifre_tekrar" minlength="6" required></div>
          <div class="islemler"><button class="buton koyu" type="submit">Şifre Değiştir</button></div>
        </form>
      </section>

    <?php else: ?>

      <section id="musteri-randevu" class="kutu">
        <div class="baslik"><h3>Randevu Al</h3><div class="soluk">Berber seç, tarih seç, uygun saatleri gör</div></div>

        <form method="get" class="form-grid" style="margin-bottom:18px">
          <input type="hidden" name="sayfa" value="panel">
          <div>
            <label>Berber</label>
            <select name="berber_id" required>
              <option value="">Seçiniz</option>
              <?php foreach ($barbers as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= $selectedBarber === (int)$b['id'] ? 'selected' : '' ?>>
                  <?= e($b['ad_soyad']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Tarih</label>
            <input type="date" name="tarih" min="<?= e(nowDate()) ?>" value="<?= e($selectedDate) ?>" required>
          </div>
          <div class="islemler">
            <button class="buton koyu" type="submit">Saatleri Göster</button>
          </div>
        </form>

        <?php if ($selectedBarber > 0 && $selectedBarberLeave): ?>
          <div class="mesaj hata">Bu berber seçilen tarihte izinli.</div>
        <?php elseif ($selectedBarber > 0 && $selectedBarberClosed): ?>
          <div class="mesaj hata">Bu berber seçilen günde çalışmıyor.</div>
        <?php endif; ?>

        <form method="post" class="form-grid">
          <input type="hidden" name="islem" value="randevu_olustur">
          <input type="hidden" name="berber_id" value="<?= (int)$selectedBarber ?>">
          <input type="hidden" name="tarih" value="<?= e($selectedDate) ?>">
          <input type="hidden" name="saat" id="secilen_saat">

          <div>
            <label>Hizmet</label>
            <select name="hizmet_id" required>
              <option value="">Seçiniz</option>
              <?php foreach ($customerVisibleServices as $h): ?>
                <option value="<?= (int)$h['id'] ?>"><?= e($h['ad']) ?> · <?= (int)$h['fiyat'] ?> TL · <?= (int)$h['sure_dk'] ?> dk</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Seçilen Berber</label>
            <input readonly value="<?php
              $name = '';
              foreach ($barbers as $b) { if ((int)$b['id'] === $selectedBarber) { $name = $b['ad_soyad']; break; } }
              echo e($name ?: 'Önce berber seç');
            ?>">
          </div>

          <div>
            <label>Seçilen Tarih</label>
            <input readonly value="<?= e($selectedDate) ?>">
          </div>

          <div>
            <label>Not</label>
            <textarea name="not_metni" placeholder="İstersen açıklama yaz"></textarea>
          </div>

          <div>
            <label>Saatler</label>
            <?php if ($selectedBarber <= 0): ?>
              <div class="kucuk">Önce berber ve tarih seçip “Saatleri Göster” butonuna bas.</div>
            <?php elseif ($selectedBarberLeave || $selectedBarberClosed): ?>
              <div class="kucuk">Uygun saat yok.</div>
            <?php elseif (!$visibleSlots): ?>
              <div class="kucuk">Uygun saat bulunamadı.</div>
            <?php else: ?>
              <div class="saat-grid">
                <?php foreach ($visibleSlots as $slot): ?>
                  <?php if ($slot['durum'] === 'bos'): ?>
                    <button type="button" class="saat-btn bos" onclick="saatSec('<?= e($slot['saat']) ?>', this)">
                      <?= e($slot['saat']) ?> · Boş
                    </button>
                  <?php elseif ($slot['durum'] === 'dolu'): ?>
                    <button type="button" class="saat-btn dolu" disabled>
                      <?= e($slot['saat']) ?> · Dolu
                    </button>
                  <?php else: ?>
                    <button type="button" class="saat-btn mola" disabled>
                      <?= e($slot['saat']) ?> · Mola
                    </button>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="islemler">
            <button class="buton altin" type="submit">Randevu Oluştur</button>
          </div>
        </form>
      </section>

      <section id="musteri-randevular" class="kutu" style="margin-top:18px">
        <div class="baslik"><h3>Randevularım</h3><div class="soluk">Sadece kendi randevuların</div></div>
        <table class="tablo">
          <thead><tr><th>Tarih</th><th>Saat</th><th>Berber</th><th>Hizmet</th><th>Ücret</th><th>Durum</th><th>İşlem</th></tr></thead>
          <tbody>
            <?php foreach ($myAppointments as $r): ?>
              <tr>
                <td><?= e($r['tarih']) ?></td>
                <td><?= e(substr((string)$r['saat'],0,5)) ?></td>
                <td><?= e($r['berber_adi']) ?></td>
                <td><?= e($r['hizmet_adi']) ?></td>
                <td><?= number_format((int)$r['fiyat'],0,',','.') ?> TL</td>
                <td><span class="rozet <?= e($r['durum']) ?>"><?= e(statusText($r['durum'])) ?></span></td>
                <td>
                  <?php if (!in_array($r['durum'], ['tamamlandi','iptal'], true)): ?>
                    <form method="post">
                      <input type="hidden" name="islem" value="randevu_durum_degistir">
                      <input type="hidden" name="randevu_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="yeni_durum" value="iptal">
                      <button class="buton koyu" type="submit">İptal</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>

      <section class="kutu" id="ayarlar" style="margin-top:18px">
        <div class="baslik"><h3>Kullanıcı Bilgisi</h3><div class="soluk">Aktif oturum</div></div>
        <div class="kv">
          <div class="cizgi"><span>Ad Soyad</span><strong><?= e(user()['ad_soyad']) ?></strong></div>
          <div class="cizgi"><span>Telefon</span><strong><?= e(user()['telefon']) ?></strong></div>
          <div class="cizgi"><span>Rol</span><strong><?= e(roleText(user()['rol'])) ?></strong></div>
        </div>

        <form method="post" class="form-grid" style="margin-top:18px">
          <input type="hidden" name="islem" value="sifre_degistir">
          <div><label>Mevcut Şifre</label><input type="password" name="eski_sifre" required></div>
          <div><label>Yeni Şifre</label><input type="password" name="yeni_sifre" minlength="6" required></div>
          <div><label>Yeni Şifre Tekrar</label><input type="password" name="yeni_sifre_tekrar" minlength="6" required></div>
          <div class="islemler"><button class="buton koyu" type="submit">Şifre Değiştir</button></div>
        </form>
      </section>

    <?php endif; ?>
  </main>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function(){
  const qr = document.getElementById('qrcode');
  if (qr && window.QRCode) {
    new QRCode(qr, {
      text: <?= json_encode($qrLink, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      width: 180,
      height: 180,
      colorDark: "#111111",
      colorLight: "#ffffff",
      correctLevel: QRCode.CorrectLevel.H
    });
  }
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(<?= json_encode(baseUrl() . '?sw=1') ?>).catch(()=>{});
  }
})();

function saatSec(saat, el) {
  const input = document.getElementById('secilen_saat');
  if (!input) return;
  input.value = saat;
  document.querySelectorAll('.saat-btn.bos').forEach(btn => btn.classList.remove('secili'));
  el.classList.add('secili');
}
</script>
<?php endif; ?>
</body>
</html>

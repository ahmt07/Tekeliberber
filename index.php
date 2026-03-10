<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Europe/Istanbul');

$db = new PDO('sqlite:' . __DIR__ . '/barber.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function nowDate(): string {
    return date('Y-m-d');
}

function nowTime(): string {
    return date('H:i');
}

function isLoggedIn(): bool {
    return isset($_SESSION['user']);
}

function user(): ?array {
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ?view=login');
        exit;
    }
}

function requireRole(string $role): void {
    requireLogin();
    if ((user()['role'] ?? '') !== $role) {
        header('Location: ?view=dashboard');
        exit;
    }
}

function flash(?string $type = null, ?string $message = null): ?array {
    if ($type !== null && $message !== null) {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function seedDatabase(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('admin','barber','customer')),
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            duration_min INTEGER NOT NULL,
            price INTEGER NOT NULL,
            active INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS appointments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL,
            barber_id INTEGER NOT NULL,
            service_id INTEGER NOT NULL,
            appt_date TEXT NOT NULL,
            appt_time TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('pending','approved','completed','cancelled')) DEFAULT 'pending',
            note TEXT DEFAULT '',
            created_at TEXT NOT NULL,
            FOREIGN KEY(customer_id) REFERENCES users(id),
            FOREIGN KEY(barber_id) REFERENCES users(id),
            FOREIGN KEY(service_id) REFERENCES services(id)
        );
    ");

    $countUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($countUsers === 0) {
        $stmt = $db->prepare("INSERT INTO users (name, phone, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)");
        $defaultPass = password_hash('123456', PASSWORD_DEFAULT);
        $now = date('c');

        $stmt->execute(['Ahmet Tekeli', '05000000000', $defaultPass, 'admin', $now]);
        $stmt->execute(['Mehmet Kara', '05000000001', $defaultPass, 'barber', $now]);
        $stmt->execute(['Hasan Çelik', '05000000002', $defaultPass, 'barber', $now]);
        $stmt->execute(['Demo Müşteri', '05000000003', $defaultPass, 'customer', $now]);
    }

    $countServices = (int)$db->query("SELECT COUNT(*) FROM services")->fetchColumn();
    if ($countServices === 0) {
        $stmt = $db->prepare("INSERT INTO services (name, duration_min, price, active) VALUES (?, ?, ?, 1)");
        $stmt->execute(['Saç Kesim', 30, 300]);
        $stmt->execute(['Sakal', 15, 150]);
        $stmt->execute(['Saç + Sakal', 45, 400]);
        $stmt->execute(['Çocuk Tıraşı', 25, 250]);
    }

    $countAppts = (int)$db->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
    if ($countAppts === 0) {
        $users = $db->query("SELECT id, role FROM users")->fetchAll(PDO::FETCH_ASSOC);
        $adminId = null;
        $barberIds = [];
        $customerId = null;
        foreach ($users as $u) {
            if ($u['role'] === 'admin') $adminId = (int)$u['id'];
            if ($u['role'] === 'barber') $barberIds[] = (int)$u['id'];
            if ($u['role'] === 'customer' && $customerId === null) $customerId = (int)$u['id'];
        }
        $serviceIds = $db->query("SELECT id FROM services")->fetchAll(PDO::FETCH_COLUMN);
        if ($customerId && count($barberIds) >= 2 && count($serviceIds) >= 3) {
            $stmt = $db->prepare("
                INSERT INTO appointments (customer_id, barber_id, service_id, appt_date, appt_time, status, note, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $today = nowDate();
            $created = date('c');
            $stmt->execute([$customerId, $barberIds[0], $serviceIds[0], $today, '10:00', 'completed', 'Demo randevu', $created]);
            $stmt->execute([$customerId, $barberIds[1], $serviceIds[2], $today, '11:00', 'approved', 'Demo randevu', $created]);
            $stmt->execute([$customerId, $barberIds[0], $serviceIds[1], $today, '13:30', 'pending', 'Demo randevu', $created]);
        }
    }
}
seedDatabase($db);

function refreshSessionUser(PDO $db): void {
    if (!isset($_SESSION['user']['id'])) return;
    $stmt = $db->prepare("SELECT id, name, phone, role, active FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user']['id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || (int)$u['active'] !== 1) {
        unset($_SESSION['user']);
        return;
    }
    $_SESSION['user'] = $u;
}
refreshSessionUser($db);

function loginUser(PDO $db, string $phone, string $password): bool {
    $stmt = $db->prepare("SELECT id, name, phone, role, active, password_hash FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || (int)$u['active'] !== 1) return false;
    if (!password_verify($password, $u['password_hash'])) return false;
    unset($u['password_hash']);
    $_SESSION['user'] = $u;
    return true;
}

function getStats(PDO $db): array {
    $today = nowDate();
    $dailyAppointments = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE appt_date = '$today' AND status != 'cancelled'")->fetchColumn();
    $barberCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='barber' AND active=1")->fetchColumn();
    $customerCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='customer' AND active=1")->fetchColumn();

    $revenueStmt = $db->query("
        SELECT COALESCE(SUM(s.price),0)
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        WHERE a.appt_date = '$today' AND a.status = 'completed'
    ");
    $dailyRevenue = (int)$revenueStmt->fetchColumn();

    return [
        'dailyAppointments' => $dailyAppointments,
        'barberCount' => $barberCount,
        'customerCount' => $customerCount,
        'dailyRevenue' => $dailyRevenue,
    ];
}

function getRevenueSeries(PDO $db): array {
    $out = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i day"));
        $stmt = $db->query("
            SELECT COALESCE(SUM(s.price),0)
            FROM appointments a
            JOIN services s ON s.id = a.service_id
            WHERE a.appt_date = '$date' AND a.status = 'completed'
        ");
        $out[] = [
            'label' => date('d M', strtotime($date)),
            'value' => (int)$stmt->fetchColumn()
        ];
    }
    return $out;
}

function appointmentExists(PDO $db, int $barberId, string $date, string $time): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM appointments
        WHERE barber_id = ? AND appt_date = ? AND appt_time = ? AND status != 'cancelled'
    ");
    $stmt->execute([$barberId, $date, $time]);
    return (int)$stmt->fetchColumn() > 0;
}

function allBarbers(PDO $db): array {
    return $db->query("SELECT id, name FROM users WHERE role='barber' AND active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

function allServices(PDO $db): array {
    return $db->query("SELECT id, name, duration_min, price FROM services WHERE active=1 ORDER BY price")->fetchAll(PDO::FETCH_ASSOC);
}

function statusLabel(string $s): string {
    return match($s) {
        'pending' => 'Bekliyor',
        'approved' => 'Onaylı',
        'completed' => 'Tamamlandı',
        'cancelled' => 'İptal',
        default => $s
    };
}

function isPost(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* ------------------ ACTIONS ------------------ */

if (isPost()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $name = trim($_POST['name'] ?? '');
        $phone = preg_replace('/\D+/', '', $_POST['phone'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($name === '' || strlen($phone) < 10 || strlen($password) < 6) {
            flash('error', 'Bilgileri eksiksiz gir. Şifre en az 6 karakter olmalı.');
            header('Location: ?view=register');
            exit;
        }

        $check = $db->prepare("SELECT COUNT(*) FROM users WHERE phone = ?");
        $check->execute([$phone]);
        if ((int)$check->fetchColumn() > 0) {
            flash('error', 'Bu telefon ile kayıt zaten var.');
            header('Location: ?view=register');
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO users (name, phone, password_hash, role, active, created_at)
            VALUES (?, ?, ?, 'customer', 1, ?)
        ");
        $stmt->execute([$name, $phone, password_hash($password, PASSWORD_DEFAULT), date('c')]);

        flash('success', 'Kayıt başarılı. Giriş yapabilirsin.');
        header('Location: ?view=login');
        exit;
    }

    if ($action === 'login') {
        $phone = preg_replace('/\D+/', '', $_POST['phone'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if (loginUser($db, $phone, $password)) {
            flash('success', 'Hoş geldin.');
            header('Location: ?view=dashboard');
            exit;
        }
        flash('error', 'Telefon veya şifre hatalı.');
        header('Location: ?view=login');
        exit;
    }

    if ($action === 'logout') {
        session_destroy();
        header('Location: ?view=login');
        exit;
    }

    if ($action === 'book_appointment') {
        requireRole('customer');

        $barberId = (int)($_POST['barber_id'] ?? 0);
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $date = (string)($_POST['appt_date'] ?? '');
        $time = (string)($_POST['appt_time'] ?? '');
        $note = trim((string)($_POST['note'] ?? ''));

        if ($barberId <= 0 || $serviceId <= 0 || !$date || !$time) {
            flash('error', 'Tüm alanları doldur.');
            header('Location: ?view=dashboard');
            exit;
        }

        if ($date < nowDate()) {
            flash('error', 'Geçmiş tarihe randevu oluşturamazsın.');
            header('Location: ?view=dashboard');
            exit;
        }

        if (appointmentExists($db, $barberId, $date, $time)) {
            flash('error', 'Bu saat dolu. Başka saat seç.');
            header('Location: ?view=dashboard');
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO appointments (customer_id, barber_id, service_id, appt_date, appt_time, status, note, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)
        ");
        $stmt->execute([
            (int)user()['id'],
            $barberId,
            $serviceId,
            $date,
            $time,
            $note,
            date('c')
        ]);

        flash('success', 'Randevu oluşturuldu.');
        header('Location: ?view=dashboard');
        exit;
    }

    if ($action === 'change_appointment_status') {
        requireLogin();
        $apptId = (int)($_POST['appt_id'] ?? 0);
        $newStatus = (string)($_POST['new_status'] ?? '');
        $allowed = ['pending','approved','completed','cancelled'];
        if (!in_array($newStatus, $allowed, true)) {
            flash('error', 'Geçersiz işlem.');
            header('Location: ?view=dashboard');
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM appointments WHERE id = ?");
        $stmt->execute([$apptId]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$appt) {
            flash('error', 'Randevu bulunamadı.');
            header('Location: ?view=dashboard');
            exit;
        }

        $role = user()['role'];
        $uid = (int)user()['id'];

        $authorized = false;
        if ($role === 'admin') $authorized = true;
        if ($role === 'barber' && (int)$appt['barber_id'] === $uid) $authorized = true;
        if ($role === 'customer' && (int)$appt['customer_id'] === $uid && $newStatus === 'cancelled') $authorized = true;

        if (!$authorized) {
            flash('error', 'Bu işlem için yetkin yok.');
            header('Location: ?view=dashboard');
            exit;
        }

        $up = $db->prepare("UPDATE appointments SET status = ? WHERE id = ?");
        $up->execute([$newStatus, $apptId]);

        flash('success', 'Randevu durumu güncellendi.');
        header('Location: ?view=dashboard');
        exit;
    }

    if ($action === 'create_service') {
        requireRole('admin');
        $name = trim($_POST['name'] ?? '');
        $duration = (int)($_POST['duration'] ?? 0);
        $price = (int)($_POST['price'] ?? 0);

        if ($name === '' || $duration <= 0 || $price <= 0) {
            flash('error', 'Hizmet bilgileri geçersiz.');
            header('Location: ?view=dashboard#services');
            exit;
        }

        $stmt = $db->prepare("INSERT INTO services (name, duration_min, price, active) VALUES (?, ?, ?, 1)");
        $stmt->execute([$name, $duration, $price]);

        flash('success', 'Hizmet eklendi.');
        header('Location: ?view=dashboard#services');
        exit;
    }

    if ($action === 'create_barber') {
        requireRole('admin');
        $name = trim($_POST['name'] ?? '');
        $phone = preg_replace('/\D+/', '', $_POST['phone'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($name === '' || strlen($phone) < 10 || strlen($password) < 6) {
            flash('error', 'Berber bilgileri eksik.');
            header('Location: ?view=dashboard#barbers');
            exit;
        }

        $check = $db->prepare("SELECT COUNT(*) FROM users WHERE phone = ?");
        $check->execute([$phone]);
        if ((int)$check->fetchColumn() > 0) {
            flash('error', 'Bu telefon zaten kayıtlı.');
            header('Location: ?view=dashboard#barbers');
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO users (name, phone, password_hash, role, active, created_at)
            VALUES (?, ?, ?, 'barber', 1, ?)
        ");
        $stmt->execute([$name, $phone, password_hash($password, PASSWORD_DEFAULT), date('c')]);

        flash('success', 'Berber eklendi.');
        header('Location: ?view=dashboard#barbers');
        exit;
    }
}

$view = $_GET['view'] ?? (isLoggedIn() ? 'dashboard' : 'login');
$f = flash();

$stats = getStats($db);
$series = getRevenueSeries($db);

$services = allServices($db);
$barbers = allBarbers($db);

$todayAppointments = $db->query("
    SELECT a.*, c.name AS customer_name, b.name AS barber_name, s.name AS service_name, s.price AS service_price
    FROM appointments a
    JOIN users c ON c.id = a.customer_id
    JOIN users b ON b.id = a.barber_id
    JOIN services s ON s.id = a.service_id
    WHERE a.appt_date = '" . nowDate() . "'
    ORDER BY a.appt_time ASC
")->fetchAll(PDO::FETCH_ASSOC);

$myAppointments = [];
if (isLoggedIn()) {
    $u = user();
    if ($u['role'] === 'customer') {
        $stmt = $db->prepare("
            SELECT a.*, b.name AS barber_name, s.name AS service_name, s.price AS service_price
            FROM appointments a
            JOIN users b ON b.id = a.barber_id
            JOIN services s ON s.id = a.service_id
            WHERE a.customer_id = ?
            ORDER BY a.appt_date DESC, a.appt_time DESC
        ");
        $stmt->execute([(int)$u['id']]);
        $myAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($u['role'] === 'barber') {
        $stmt = $db->prepare("
            SELECT a.*, c.name AS customer_name, s.name AS service_name, s.price AS service_price
            FROM appointments a
            JOIN users c ON c.id = a.customer_id
            JOIN services s ON s.id = a.service_id
            WHERE a.barber_id = ?
            ORDER BY a.appt_date DESC, a.appt_time DESC
        ");
        $stmt->execute([(int)$u['id']]);
        $myAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$topCustomers = $db->query("
    SELECT u.name, COUNT(a.id) AS total_appts, COALESCE(SUM(s.price),0) AS total_amount
    FROM users u
    LEFT JOIN appointments a ON a.customer_id = u.id AND a.status = 'completed'
    LEFT JOIN services s ON s.id = a.service_id
    WHERE u.role = 'customer'
    GROUP BY u.id
    ORDER BY total_amount DESC, total_appts DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$barberPerformance = $db->query("
    SELECT u.name,
           COUNT(a.id) AS total_jobs,
           COALESCE(SUM(CASE WHEN a.status='completed' THEN s.price ELSE 0 END),0) AS total_amount
    FROM users u
    LEFT JOIN appointments a ON a.barber_id = u.id
    LEFT JOIN services s ON s.id = a.service_id
    WHERE u.role = 'barber' AND u.active = 1
    GROUP BY u.id
    ORDER BY total_amount DESC, total_jobs DESC
")->fetchAll(PDO::FETCH_ASSOC);

$allUsers = [];
if (isLoggedIn() && user()['role'] === 'admin') {
    $allUsers = $db->query("SELECT id, name, phone, role, active, created_at FROM users ORDER BY role, name")->fetchAll(PDO::FETCH_ASSOC);
}

$qrText = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'], '?') . '?view=login';
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ahmet Tekeli Barber - Full Sistem</title>
<style>
:root{
    --bg:#0b0909;
    --panel:#141111;
    --panel2:#191313;
    --line:#3a2a1e;
    --gold:#d4af37;
    --gold2:#f1d17a;
    --text:#f6efe3;
    --muted:#c5b59e;
    --danger:#ff7777;
    --success:#7ae28f;
    --shadow:0 20px 60px rgba(0,0,0,.35);
    --radius:24px;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:
radial-gradient(circle at top right, rgba(212,175,55,.12), transparent 25%),
radial-gradient(circle at bottom left, rgba(212,175,55,.08), transparent 20%),
linear-gradient(180deg,#1a0f0a 0%, #0a0908 100%);
color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}
.page{
    min-height:100vh;
    display:flex;
}
.sidebar{
    width:260px;
    background:linear-gradient(180deg, rgba(19,16,16,.97), rgba(9,8,8,.98));
    border-right:1px solid rgba(212,175,55,.12);
    padding:22px;
    position:sticky; top:0; height:100vh;
}
.brand{
    display:flex;align-items:center;gap:14px;
    padding:14px;border:1px solid rgba(212,175,55,.18);
    background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01));
    border-radius:18px;
    box-shadow:var(--shadow);
}
.logo{
    width:44px;height:44px;border-radius:14px;
    display:grid;place-items:center;
    background:radial-gradient(circle at 30% 30%, #33230f, #120f0d);
    border:1px solid rgba(212,175,55,.35);
    color:var(--gold2);font-weight:800;
}
.brand h1{font-size:15px;line-height:1.2;margin:0;color:var(--gold2);font-weight:800}
.brand p{margin:2px 0 0;color:var(--muted);font-size:11px}

.nav{margin-top:22px;display:grid;gap:8px}
.nav a{
    display:flex;align-items:center;gap:12px;
    padding:14px 16px;border-radius:14px;
    color:#e8dcc6;border:1px solid transparent;
    transition:.2s ease;
}
.nav a:hover,.nav a.active{
    background:rgba(212,175,55,.08);
    border-color:rgba(212,175,55,.18);
    color:#fff;
}
.nav .ico{
    width:18px;text-align:center;color:var(--gold2)
}
.sidebar .foot{
    position:absolute;left:22px;right:22px;bottom:22px;
    display:grid;gap:10px
}
.btn{
    border:none;cursor:pointer;border-radius:14px;
    padding:12px 16px;font-weight:700
}
.btn-gold{
    background:linear-gradient(180deg, #f1d17a, #c89a2c);
    color:#1b1308;
    box-shadow:0 10px 30px rgba(212,175,55,.25);
}
.btn-dark{
    background:#1f1919;color:#f3eadc;border:1px solid rgba(212,175,55,.18)
}
.main{
    flex:1;padding:24px;
}
.topbar{
    display:flex;justify-content:space-between;align-items:center;
    gap:20px;padding:10px 2px 22px
}
.topbar h2{margin:0;font-size:34px;color:#fff}
.userbox{
    display:flex;align-items:center;gap:12px;
    padding:10px 14px;border-radius:18px;background:rgba(255,255,255,.03);
    border:1px solid rgba(212,175,55,.15)
}
.avatar{
    width:42px;height:42px;border-radius:50%;
    background:linear-gradient(180deg,#6b4f23,#251c11);
    display:grid;place-items:center;font-weight:800;color:#fff
}
.grid{
    display:grid;gap:18px
}
.cards4{grid-template-columns:repeat(4,minmax(0,1fr))}
.card{
    background:linear-gradient(180deg, rgba(23,18,18,.92), rgba(17,13,13,.94));
    border:1px solid rgba(212,175,55,.14);
    border-radius:var(--radius);
    padding:20px;
    box-shadow:var(--shadow);
}
.metric{
    display:flex;justify-content:space-between;align-items:center;gap:12px
}
.metric .left small{color:var(--muted);display:block;margin-bottom:6px}
.metric .left strong{font-size:38px;color:var(--gold2);line-height:1}
.metric .mi{
    width:54px;height:54px;border-radius:18px;display:grid;place-items:center;
    background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.18);
    color:var(--gold2);font-size:22px
}
.layout-main{
    margin-top:18px;
    display:grid;grid-template-columns:2fr 1fr;gap:18px
}
.section-title{
    display:flex;justify-content:space-between;align-items:center;gap:12px;
    margin-bottom:14px
}
.section-title h3{margin:0;font-size:28px}
.section-title .muted{color:var(--muted);font-size:13px}
.chart{
    height:220px;padding:14px 8px 6px 8px;
    position:relative;border-radius:18px;
    background:
      linear-gradient(to top, rgba(212,175,55,.06) 1px, transparent 1px) 0 0/100% 20%,
      linear-gradient(to right, rgba(212,175,55,.04) 1px, transparent 1px) 0 0/12.5% 100%;
}
.chart svg{width:100%;height:100%}
.list{
    display:grid;gap:10px
}
.row{
    display:grid;gap:12px;align-items:center;
    grid-template-columns:64px 1fr auto auto;
    padding:14px;border-radius:16px;
    border:1px solid rgba(212,175,55,.10);
    background:rgba(255,255,255,.02)
}
.row .mini-avatar{
    width:48px;height:48px;border-radius:50%;
    background:linear-gradient(180deg,#5e431c,#271d13);
    display:grid;place-items:center;font-weight:800
}
.badge{
    display:inline-flex;align-items:center;gap:8px;
    padding:8px 12px;border-radius:999px;
    font-size:12px;font-weight:700
}
.badge.pending{background:rgba(255,195,0,.12);color:#ffda70;border:1px solid rgba(255,195,0,.2)}
.badge.approved{background:rgba(85,170,255,.12);color:#9ad4ff;border:1px solid rgba(85,170,255,.2)}
.badge.completed{background:rgba(122,226,143,.10);color:#9af2ab;border:1px solid rgba(122,226,143,.2)}
.badge.cancelled{background:rgba(255,119,119,.12);color:#ff9b9b;border:1px solid rgba(255,119,119,.2)}
.kv{display:grid;gap:10px}
.kv .line{
    display:flex;justify-content:space-between;gap:12px;
    padding:10px 0;border-bottom:1px dashed rgba(212,175,55,.12)
}
.kv .line:last-child{border-bottom:none}
.forms{
    display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:18px
}
.form-grid{display:grid;gap:12px}
label{font-size:13px;color:var(--muted);display:block;margin-bottom:6px}
input,select,textarea{
    width:100%;
    padding:14px 14px;border-radius:14px;
    border:1px solid rgba(212,175,55,.16);
    background:#120f0f;color:#fff;outline:none
}
textarea{min-height:92px;resize:vertical}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.flash{
    margin-bottom:18px;padding:14px 16px;border-radius:16px;font-weight:700
}
.flash.success{background:rgba(122,226,143,.12);color:#aaf3b7;border:1px solid rgba(122,226,143,.18)}
.flash.error{background:rgba(255,119,119,.12);color:#ffb0b0;border:1px solid rgba(255,119,119,.18)}
.auth-wrap{
    min-height:100vh;display:grid;place-items:center;padding:24px
}
.auth-box{
    width:min(100%,480px);padding:24px;border-radius:28px;
    border:1px solid rgba(212,175,55,.16);
    background:linear-gradient(180deg, rgba(20,16,16,.96), rgba(12,10,10,.98));
    box-shadow:var(--shadow)
}
.auth-head{text-align:center;margin-bottom:18px}
.auth-head h2{margin:10px 0 6px;font-size:32px;color:var(--gold2)}
.auth-head p{margin:0;color:var(--muted)}
.table{width:100%;border-collapse:collapse}
.table th,.table td{
    text-align:left;padding:12px;border-bottom:1px solid rgba(212,175,55,.1);
    vertical-align:top
}
.table th{color:var(--gold2);font-size:13px}
.qr-box{
    display:grid;grid-template-columns:230px 1fr;gap:18px;align-items:center
}
.qr-target{
    width:210px;height:210px;border-radius:24px;background:#fff;padding:14px
}
.small{font-size:12px;color:var(--muted)}
.tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.tab{
    padding:10px 14px;border-radius:999px;border:1px solid rgba(212,175,55,.16);
    background:#151111;color:#f3eadc;font-weight:700
}
.tab.active{background:rgba(212,175,55,.12);color:#fff}
.section-anchor{scroll-margin-top:18px}
@media (max-width:1200px){
    .cards4{grid-template-columns:repeat(2,minmax(0,1fr))}
    .layout-main{grid-template-columns:1fr}
}
@media (max-width:900px){
    .page{display:block}
    .sidebar{width:auto;height:auto;position:relative}
    .main{padding:16px}
    .forms,.cards4,.qr-box{grid-template-columns:1fr}
    .row{grid-template-columns:56px 1fr}
    .row > :nth-child(3), .row > :nth-child(4){grid-column:2}
    .topbar{flex-direction:column;align-items:flex-start}
}
</style>
</head>
<body>

<?php if ($view === 'login' || $view === 'register'): ?>
<div class="auth-wrap">
    <div class="auth-box">
        <div class="auth-head">
            <div class="logo" style="margin:0 auto">AT</div>
            <h2>Ahmet Tekeli Barber</h2>
            <p>Premium randevu ve yönetim sistemi</p>
        </div>

        <?php if ($f): ?>
            <div class="flash <?= h($f['type']) ?>"><?= h($f['message']) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <a class="tab <?= $view==='login' ? 'active' : '' ?>" href="?view=login">Giriş Yap</a>
            <a class="tab <?= $view==='register' ? 'active' : '' ?>" href="?view=register">Kayıt Ol</a>
        </div>

        <?php if ($view === 'login'): ?>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="login">
                <div>
                    <label>Telefon</label>
                    <input name="phone" placeholder="05XXXXXXXXX" required>
                </div>
                <div>
                    <label>Şifre</label>
                    <input type="password" name="password" placeholder="******" required>
                </div>
                <button class="btn btn-gold" type="submit">Giriş Yap</button>
            </form>
            <div style="margin-top:16px" class="small">
                Demo admin: <b>05000000000</b> / <b>123456</b><br>
                Demo berber: <b>05000000001</b> / <b>123456</b>
            </div>
        <?php else: ?>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="register">
                <div>
                    <label>Ad Soyad</label>
                    <input name="name" required>
                </div>
                <div>
                    <label>Telefon</label>
                    <input name="phone" placeholder="05XXXXXXXXX" required>
                </div>
                <div>
                    <label>Şifre</label>
                    <input type="password" name="password" minlength="6" required>
                </div>
                <button class="btn btn-gold" type="submit">Kayıt Ol</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php else: requireLogin(); $u = user(); ?>
<div class="page">
    <aside class="sidebar">
        <div class="brand">
            <div class="logo">AT</div>
            <div>
                <h1>AHMET TEKELİ<br>BARBER</h1>
                <p>FULL SİSTEM</p>
            </div>
        </div>

        <nav class="nav">
            <a href="?view=dashboard" class="active"><span class="ico">⌂</span> Anasayfa</a>
            <a href="#appointments"><span class="ico">🗓</span> Randevular</a>
            <?php if ($u['role'] === 'admin'): ?>
                <a href="#barbers"><span class="ico">✂</span> Berberler</a>
                <a href="#customers"><span class="ico">👥</span> Müşteriler</a>
                <a href="#services"><span class="ico">🧾</span> Hizmetler</a>
            <?php endif; ?>
            <a href="#qr"><span class="ico">⌁</span> QR Kod Oluştur</a>
            <a href="#settings"><span class="ico">⚙</span> Kullanıcı Ayarları</a>
        </nav>

        <div class="foot">
            <form method="post">
                <input type="hidden" name="action" value="logout">
                <button class="btn btn-dark" type="submit" style="width:100%">Çıkış Yap</button>
            </form>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <div class="small">Hoş geldin</div>
                <h2><?= h($u['name']) ?></h2>
            </div>
            <div class="userbox">
                <div class="avatar"><?= mb_substr(h($u['name']), 0, 1) ?></div>
                <div>
                    <div style="font-weight:800"><?= h($u['name']) ?></div>
                    <div class="small">
                        <?= $u['role']==='admin' ? 'Yönetici' : ($u['role']==='barber' ? 'Berber' : 'Müşteri') ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($f): ?>
            <div class="flash <?= h($f['type']) ?>"><?= h($f['message']) ?></div>
        <?php endif; ?>

        <section class="grid cards4">
            <div class="card">
                <div class="metric">
                    <div class="left">
                        <small>Günlük Randevu</small>
                        <strong><?= $stats['dailyAppointments'] ?></strong>
                    </div>
                    <div class="mi">🗓</div>
                </div>
            </div>
            <div class="card">
                <div class="metric">
                    <div class="left">
                        <small>Günlük Gelir</small>
                        <strong><?= number_format($stats['dailyRevenue'],0,',','.') ?> TL</strong>
                    </div>
                    <div class="mi">₺</div>
                </div>
            </div>
            <div class="card">
                <div class="metric">
                    <div class="left">
                        <small>Berber Sayısı</small>
                        <strong><?= $stats['barberCount'] ?></strong>
                    </div>
                    <div class="mi">✂</div>
                </div>
            </div>
            <div class="card">
                <div class="metric">
                    <div class="left">
                        <small>Toplam Müşteri</small>
                        <strong><?= $stats['customerCount'] ?></strong>
                    </div>
                    <div class="mi">👤</div>
                </div>
            </div>
        </section>

        <section class="layout-main">
            <div class="card">
                <div class="section-title">
                    <h3>Gelir Grafiği</h3>
                    <div class="muted">Son 7 gün</div>
                </div>
                <div class="chart">
                    <?php
                        $values = array_column($series, 'value');
                        $max = max(1, max($values));
                        $points = [];
                        foreach ($series as $i => $row) {
                            $x = 30 + ($i * (600 / max(1, count($series)-1)));
                            $y = 170 - (($row['value'] / $max) * 140);
                            $points[] = round($x, 2) . ',' . round($y, 2);
                        }
                    ?>
                    <svg viewBox="0 0 660 190" preserveAspectRatio="none">
                        <polyline points="<?= h(implode(' ', $points)) ?>" fill="none" stroke="#d4af37" stroke-width="3"/>
                        <?php foreach ($series as $i => $row):
                            $x = 30 + ($i * (600 / max(1, count($series)-1)));
                            $y = 170 - (($row['value'] / $max) * 140);
                        ?>
                            <circle cx="<?= $x ?>" cy="<?= $y ?>" r="4.5" fill="#f1d17a"></circle>
                            <text x="<?= $x-12 ?>" y="186" fill="#c5b59e" font-size="10"><?= h($row['label']) ?></text>
                        <?php endforeach; ?>
                    </svg>
                </div>
            </div>

            <div class="card">
                <div class="section-title">
                    <h3>Günlük Ciro</h3>
                    <div class="muted"><?= number_format($stats['dailyRevenue'],0,',','.') ?> TL</div>
                </div>
                <div class="kv">
                    <?php
                    $dailyServices = $db->query("
                        SELECT s.name, COUNT(a.id) AS adet, COALESCE(SUM(CASE WHEN a.status='completed' THEN s.price ELSE 0 END),0) AS gelir
                        FROM services s
                        LEFT JOIN appointments a
                          ON a.service_id = s.id
                         AND a.appt_date = '" . nowDate() . "'
                         AND a.status != 'cancelled'
                        WHERE s.active = 1
                        GROUP BY s.id
                        ORDER BY gelir DESC, adet DESC
                    ")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($dailyServices as $svc):
                    ?>
                        <div class="line">
                            <div><?= h($svc['name']) ?></div>
                            <div><?= (int)$svc['adet'] ?> iş / <?= number_format((int)$svc['gelir'],0,',','.') ?> TL</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section id="appointments" class="layout-main section-anchor">
            <div class="card">
                <div class="section-title">
                    <h3>Bugünkü İş Listesi</h3>
                    <div class="muted"><?= nowDate() ?></div>
                </div>
                <div class="list">
                    <?php if (!$todayAppointments): ?>
                        <div class="small">Bugün için randevu yok.</div>
                    <?php else: foreach ($todayAppointments as $a): ?>
                        <div class="row">
                            <div class="mini-avatar"><?= mb_substr(h($a['customer_name']),0,1) ?></div>
                            <div>
                                <div style="font-weight:800"><?= h($a['customer_name']) ?></div>
                                <div class="small"><?= h($a['service_name']) ?> · <?= h($a['barber_name']) ?></div>
                            </div>
                            <div style="font-weight:800"><?= h(substr($a['appt_time'],0,5)) ?></div>
                            <div class="badge <?= h($a['status']) ?>"><?= h(statusLabel($a['status'])) ?></div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="card">
                <?php if ($u['role'] === 'customer'): ?>
                    <div class="section-title">
                        <h3>Randevu Oluştur</h3>
                        <div class="muted">Hizmet · Berber · Saat seç</div>
                    </div>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="action" value="book_appointment">
                        <div>
                            <label>Hizmet Seç</label>
                            <select name="service_id" required>
                                <option value="">Seçiniz</option>
                                <?php foreach ($services as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?> · <?= (int)$s['price'] ?> TL · <?= (int)$s['duration_min'] ?> dk</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Berber Seç</label>
                            <select name="barber_id" required>
                                <option value="">Seçiniz</option>
                                <?php foreach ($barbers as $b): ?>
                                    <option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Tarih</label>
                            <input type="date" name="appt_date" min="<?= h(nowDate()) ?>" required>
                        </div>
                        <div>
                            <label>Saat</label>
                            <input type="time" name="appt_time" required>
                        </div>
                        <div>
                            <label>Not</label>
                            <textarea name="note" placeholder="İstersen açıklama yaz"></textarea>
                        </div>
                        <div class="actions">
                            <button class="btn btn-gold" type="submit">Randevu Oluştur</button>
                        </div>
                    </form>
                <?php elseif ($u['role'] === 'barber'): ?>
                    <div class="section-title">
                        <h3>Berber Paneli</h3>
                        <div class="muted">Kendi randevuların</div>
                    </div>
                    <div class="list">
                        <?php foreach ($myAppointments as $a): ?>
                            <div class="row">
                                <div class="mini-avatar"><?= mb_substr(h($a['customer_name']),0,1) ?></div>
                                <div>
                                    <div style="font-weight:800"><?= h($a['customer_name']) ?></div>
                                    <div class="small"><?= h($a['appt_date']) ?> · <?= h(substr($a['appt_time'],0,5)) ?> · <?= h($a['service_name']) ?></div>
                                </div>
                                <div class="badge <?= h($a['status']) ?>"><?= h(statusLabel($a['status'])) ?></div>
                                <div class="actions">
                                    <?php if ($a['status'] !== 'approved'): ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="change_appointment_status">
                                            <input type="hidden" name="appt_id" value="<?= (int)$a['id'] ?>">
                                            <input type="hidden" name="new_status" value="approved">
                                            <button class="btn btn-dark" type="submit">Onayla</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($a['status'] !== 'completed'): ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="change_appointment_status">
                                            <input type="hidden" name="appt_id" value="<?= (int)$a['id'] ?>">
                                            <input type="hidden" name="new_status" value="completed">
                                            <button class="btn btn-gold" type="submit">Tamamla</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="section-title">
                        <h3>Benim Müşteri Listem</h3>
                        <div class="muted">En çok gelen müşteriler</div>
                    </div>
                    <div class="list">
                        <?php foreach ($topCustomers as $c): ?>
                            <div class="row">
                                <div class="mini-avatar"><?= mb_substr(h($c['name']),0,1) ?></div>
                                <div>
                                    <div style="font-weight:800"><?= h($c['name']) ?></div>
                                    <div class="small"><?= (int)$c['total_appts'] ?> tamamlanan randevu</div>
                                </div>
                                <div style="font-weight:800"><?= number_format((int)$c['total_amount'],0,',','.') ?> TL</div>
                                <div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="forms">
            <div class="card" id="qr">
                <div class="section-title">
                    <h3>QR Kod Oluştur</h3>
                    <div class="muted">Müşteri direkt randevuya gelsin</div>
                </div>
                <div class="qr-box">
                    <div id="qrcode" class="qr-target"></div>
                    <div>
                        <div style="font-size:28px;font-weight:800;color:var(--gold2)">Ahmet Tekeli Barber</div>
                        <p class="small">Bu QR kodu tarayan müşteri giriş / kayıt ekranına gelir ve randevu oluşturabilir.</p>
                        <div class="card" style="padding:14px;margin-top:10px">
                            <div class="small">QR Link</div>
                            <div style="word-break:break-all"><?= h($qrText) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" id="settings">
                <div class="section-title">
                    <h3>Kullanıcı Bilgisi</h3>
                    <div class="muted">Aktif oturum</div>
                </div>
                <div class="kv">
                    <div class="line"><span>Ad Soyad</span><strong><?= h($u['name']) ?></strong></div>
                    <div class="line"><span>Telefon</span><strong><?= h($u['phone']) ?></strong></div>
                    <div class="line"><span>Rol</span><strong><?= h($u['role']) ?></strong></div>
                </div>
            </div>
        </section>

        <?php if ($u['role'] === 'customer'): ?>
            <section class="card" style="margin-top:18px">
                <div class="section-title">
                    <h3>Müşteri Paneli</h3>
                    <div class="muted">Randevularım</div>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Saat</th>
                            <th>Berber</th>
                            <th>Hizmet</th>
                            <th>Ücret</th>
                            <th>Durum</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($myAppointments as $a): ?>
                        <tr>
                            <td><?= h($a['appt_date']) ?></td>
                            <td><?= h(substr($a['appt_time'],0,5)) ?></td>
                            <td><?= h($a['barber_name']) ?></td>
                            <td><?= h($a['service_name']) ?></td>
                            <td><?= number_format((int)$a['service_price'],0,',','.') ?> TL</td>
                            <td><span class="badge <?= h($a['status']) ?>"><?= h(statusLabel($a['status'])) ?></span></td>
                            <td>
                                <?php if (!in_array($a['status'], ['completed','cancelled'], true)): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="change_appointment_status">
                                    <input type="hidden" name="appt_id" value="<?= (int)$a['id'] ?>">
                                    <input type="hidden" name="new_status" value="cancelled">
                                    <button class="btn btn-dark" type="submit">İptal</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($u['role'] === 'admin'): ?>
            <section class="layout-main" style="margin-top:18px">
                <div class="card section-anchor" id="barbers">
                    <div class="section-title">
                        <h3>Berber Performansı</h3>
                        <div class="muted">Toplam iş ve gelir</div>
                    </div>
                    <div class="list">
                        <?php foreach ($barberPerformance as $bp): ?>
                            <div class="row">
                                <div class="mini-avatar"><?= mb_substr(h($bp['name']),0,1) ?></div>
                                <div>
                                    <div style="font-weight:800"><?= h($bp['name']) ?></div>
                                    <div class="small"><?= (int)$bp['total_jobs'] ?> iş</div>
                                </div>
                                <div style="font-weight:800"><?= number_format((int)$bp['total_amount'],0,',','.') ?> TL</div>
                                <div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="section-title" style="margin-top:18px">
                        <h3>Yeni Berber Ekle</h3>
                        <div class="muted">Sisteme personel tanımla</div>
                    </div>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="action" value="create_barber">
                        <div><label>Ad Soyad</label><input name="name" required></div>
                        <div><label>Telefon</label><input name="phone" required></div>
                        <div><label>Şifre</label><input type="password" name="password" minlength="6" required></div>
                        <div class="actions"><button class="btn btn-gold" type="submit">Berber Ekle</button></div>
                    </form>
                </div>

                <div class="card section-anchor" id="services">
                    <div class="section-title">
                        <h3>Hizmetler</h3>
                        <div class="muted">Fiyat ve süre</div>
                    </div>
                    <div class="kv">
                        <?php foreach ($services as $s): ?>
                            <div class="line">
                                <span><?= h($s['name']) ?></span>
                                <strong><?= (int)$s['duration_min'] ?> dk · <?= number_format((int)$s['price'],0,',','.') ?> TL</strong>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="section-title" style="margin-top:18px">
                        <h3>Yeni Hizmet</h3>
                        <div class="muted">Servis ekle</div>
                    </div>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="action" value="create_service">
                        <div><label>Hizmet Adı</label><input name="name" required></div>
                        <div><label>Süre (dk)</label><input type="number" name="duration" min="5" required></div>
                        <div><label>Fiyat</label><input type="number" name="price" min="1" required></div>
                        <div class="actions"><button class="btn btn-gold" type="submit">Hizmet Ekle</button></div>
                    </form>
                </div>
            </section>

            <section class="card section-anchor" id="customers" style="margin-top:18px">
                <div class="section-title">
                    <h3>Tüm Kullanıcılar</h3>
                    <div class="muted">Admin · Berber · Müşteri</div>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ad Soyad</th>
                            <th>Telefon</th>
                            <th>Rol</th>
                            <th>Durum</th>
                            <th>Kayıt</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allUsers as $usr): ?>
                        <tr>
                            <td><?= h($usr['name']) ?></td>
                            <td><?= h($usr['phone']) ?></td>
                            <td><?= h($usr['role']) ?></td>
                            <td><?= (int)$usr['active'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                            <td><?= h(substr($usr['created_at'],0,10)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
    </main>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function(){
    const el = document.getElementById('qrcode');
    if (el && window.QRCode) {
        new QRCode(el, {
            text: <?= json_encode($qrText, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
            width: 180,
            height: 180,
            colorDark: "#111111",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });
    }
})();
</script>
<?php endif; ?>
</body>
</html>

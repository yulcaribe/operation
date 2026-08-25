<?php
declare(strict_types=1);

$operationOutputBufferStarted = false;
$path = '';
if (PHP_SAPI !== 'cli' && ob_get_level() === 0) {
    ob_start();
    $operationOutputBufferStarted = true;
}

try {
    require_once __DIR__ . '/bootstrap.php';
    $path = current_path();
    $flash = pull_flash();
    if ($path === '/login') {
        if (is_post()) {
            verify_csrf();
            $user = Auth::attempt((string)($_POST['identity'] ?? ''), (string)($_POST['password'] ?? ''));
            if (!$user) {
                flash('error', 'Kullanıcı adı/e-posta veya şifre hatalı.');
                redirect_to('/login');
            }
            redirect_to('/');
        }
        render_login($flash);
        exit;
    }

    if ($path === '/logout') {
        if (!is_post()) redirect_to('/login');
        verify_csrf();
        Auth::logout();
        redirect_to('/login');
    }

    $user = Auth::requireWeb();
    if ((int)$user['must_change_password'] === 1 && $path !== '/profile') redirect_to('/profile');
    if (is_standard_operation_user($user)) {
        $allowedOperationPaths = ['/', '/flight'];
        if ((int)$user['must_change_password'] === 1) $allowedOperationPaths[] = '/profile';
        if (!in_array($path, $allowedOperationPaths, true)) redirect_to('/');
    }
    if ($path === '/timeline/data') {
        render_timeline_data($user);
        exit;
    }
    $isTimelineAction = $path === '/timeline/action'
        || ($path === '/timeline' && is_post() && (string)($_POST['_timeline_ajax'] ?? '') === '1');
    if ($isTimelineAction) {
        render_timeline_action($user);
        exit;
    }
    if (is_post()) handle_action($user, $path);
    render_page($user, $path, $flash);
} catch (Throwable $error) {
    if ($operationOutputBufferStarted && ob_get_length() !== false) ob_clean();
    if ($path === '/timeline/data') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => friendly_error($error)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($path === '/timeline/action' || ($path === '/timeline' && is_post() && (string)($_POST['_timeline_ajax'] ?? '') === '1')) {
        // Bazı shared-hosting katmanları 4xx/5xx PHP gövdelerini HTML hata sayfasıyla değiştiriyor.
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => friendly_error($error)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(500);
    render_error($error);
}

function handle_action(array $user, string $path): never
{
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $redirect = $path;
    try {
        $allowedActions = [
            '/users/edit' => ['save_user', 'delete_user'],
            '/roles' => ['save_role_permissions'],
            '/airlines' => ['save_airline'],
            '/flights/edit' => ['save_flight', 'restore_flight_status', 'delete_flight'],
            '/flights/assign' => ['assign_flight', 'restore_flight_status'],
            '/flight' => ['start_flight', 'save_process', 'complete_flight'],
            '/imports' => ['stage_import', 'create_manual_flight'],
            '/imports/review' => ['update_import_rows', 'delete_import_rows', 'discard_import', 'commit_import'],
            '/timeline' => ['save_timeline_defaults', 'save_timeline_rule', 'delete_timeline_rule', 'save_timeline_flight', 'assign_timeline_flight', 'change_timeline_flight_status'],
            '/profile' => ['change_password'],
        ];
        if (!in_array($action, $allowedActions[$path] ?? [], true)) throw new RuntimeException('İşlem bu sayfadan çalıştırılamaz.');
        switch ($action) {
            case 'save_user':
                $userId = UserService::save($user, $_POST);
                $redirect = '/users/edit?id=' . $userId;
                break;
            case 'delete_user':
                UserService::delete($user, (int)($_POST['user_id'] ?? 0));
                $redirect = '/users';
                break;
            case 'save_role_permissions':
                Authorization::saveRolePermissions($user, (int)($_POST['role_id'] ?? 0), (array)($_POST['permission_ids'] ?? []));
                $redirect = '/roles';
                break;
            case 'save_airline':
                AirlineService::save($user, $_POST);
                $redirect = '/airlines';
                break;
            case 'save_flight':
                $flightId = FlightService::save($user, $_POST);
                $redirect = '/flights/edit?id=' . $flightId;
                break;
            case 'restore_flight_status':
                $flightId = (int)($_POST['flight_id'] ?? 0);
                $targetStatus = (string)($_POST['target_status'] ?? '');
                FlightService::changeStatusByAdmin($user, $flightId, $targetStatus);
                $flight = FlightService::find($flightId);
                $redirect = $targetStatus === 'scheduled' && $flight && can($user, 'flights.assign', FlightService::context($flight))
                    ? '/flights/assign?id=' . $flightId
                    : '/flights/edit?id=' . $flightId;
                break;
            case 'delete_flight':
                FlightService::delete($user, (int)($_POST['flight_id'] ?? 0));
                $redirect = '/flights';
                break;
            case 'assign_flight':
                $flightId = (int)($_POST['flight_id'] ?? 0);
                FlightService::assign($user, $flightId, (int)($_POST['user_id'] ?? 0));
                $redirect = '/flights/assign?id=' . $flightId;
                break;
            case 'start_flight':
                $flightId = (int)($_POST['flight_id'] ?? $_GET['id'] ?? 0);
                $redirect = '/flight?id=' . $flightId;
                FlightService::start($user, $flightId);
                break;
            case 'save_process':
                $flightId = (int)($_POST['flight_id'] ?? $_GET['id'] ?? 0);
                $redirect = '/flight?id=' . $flightId;
                ProcessService::save($user, $_POST);
                break;
            case 'complete_flight':
                $flightId = (int)($_POST['flight_id'] ?? $_GET['id'] ?? 0);
                $redirect = '/flight?id=' . $flightId;
                FlightService::complete($user, $flightId);
                $redirect = is_standard_operation_user($user) ? '/?tab=completed' : '/flights';
                break;
            case 'stage_import':
                $batchId = ImportService::stage($user, (array)($_FILES['excel_file'] ?? []));
                $redirect = '/imports/review?id=' . $batchId;
                break;
            case 'create_manual_flight':
                $flightId = FlightService::createManualFromImports($user, $_POST);
                $flight = FlightService::find($flightId);
                $redirect = $flight && can($user, 'flights.assign', FlightService::context($flight))
                    ? '/flights/assign?id=' . $flightId
                    : '/flights';
                break;
            case 'update_import_rows':
                $batchId = (int)($_POST['batch_id'] ?? 0);
                ImportService::updateRows($user, $batchId, (array)($_POST['rows'] ?? []));
                $redirect = '/imports/review?id=' . $batchId;
                break;
            case 'delete_import_rows':
                $batchId = (int)($_POST['batch_id'] ?? 0);
                ImportService::deleteRows($user, $batchId, (array)($_POST['row_ids'] ?? []));
                $redirect = '/imports/review?id=' . $batchId;
                break;
            case 'discard_import':
                ImportService::discard($user, (int)($_POST['batch_id'] ?? 0));
                $redirect = '/imports';
                break;
            case 'commit_import':
                $batchId = (int)($_POST['batch_id'] ?? 0);
                ImportService::commit($user, $batchId);
                $redirect = '/imports';
                break;
            case 'save_timeline_defaults':
                TimelineService::saveDefaults($user, $_POST);
                $redirect = timeline_redirect($_POST);
                break;
            case 'save_timeline_rule':
                TimelineService::saveRule($user, $_POST);
                $redirect = timeline_redirect($_POST);
                break;
            case 'delete_timeline_rule':
                TimelineService::deleteRule($user, (int)($_POST['rule_id'] ?? 0));
                $redirect = timeline_redirect($_POST);
                break;
            case 'save_timeline_flight':
                FlightService::save($user, $_POST);
                $redirect = timeline_redirect($_POST, 'timeline-board');
                break;
            case 'assign_timeline_flight':
                FlightService::assign($user, (int)($_POST['flight_id'] ?? 0), (int)($_POST['user_id'] ?? 0));
                $redirect = timeline_redirect($_POST, 'timeline-board');
                break;
            case 'change_timeline_flight_status':
                FlightService::changeStatusByAdmin($user, (int)($_POST['flight_id'] ?? 0), (string)($_POST['target_status'] ?? ''));
                $redirect = timeline_redirect($_POST, 'timeline-board');
                break;
            case 'change_password':
                Auth::changePassword($user, $_POST);
                $redirect = '/profile';
                break;
            default:
                throw new RuntimeException('İşlem bulunamadı.');
        }
        flash('success', 'İşlem başarıyla tamamlandı.');
    } catch (Throwable $error) {
        flash('error', friendly_error($error));
    }
    redirect_to($redirect);
}

function timeline_redirect(array $data, string $fragment = 'timeline-rules'): string
{
    $query = ['date' => TimelineService::normalizeDate((string)($data['timeline_date'] ?? date('Y-m-d')))];
    $airlineId = (int)($data['timeline_airline_id'] ?? 0);
    if ($airlineId > 0) $query['airline_id'] = $airlineId;
    return '/timeline?' . http_build_query($query) . '#' . $fragment;
}

function render_timeline_data(array $user): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode(
        TimelineService::data($user, (string)($_GET['date'] ?? date('Y-m-d'))),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
}

function render_timeline_action(array $user): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    try {
        if (!is_post()) throw new RuntimeException('Yalnızca POST isteği kabul edilir.');
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        switch ($action) {
            case 'save_timeline_flight':
                FlightService::save($user, $_POST);
                break;
            case 'assign_timeline_flight':
                FlightService::assign($user, (int)($_POST['flight_id'] ?? 0), (int)($_POST['user_id'] ?? 0));
                break;
            case 'change_timeline_flight_status':
                FlightService::changeStatusByAdmin($user, (int)($_POST['flight_id'] ?? 0), (string)($_POST['target_status'] ?? ''));
                break;
            default:
                throw new RuntimeException('Timeline işlemi bulunamadı.');
        }
        echo json_encode(['ok' => true, 'message' => 'İşlem başarıyla tamamlandı.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        // Hata durumu JSON içindeki `ok` alanıyla taşınır; cPanel'in yanıtı HTML'e çevirmesi önlenir.
        http_response_code(200);
        echo json_encode(['ok' => false, 'error' => friendly_error($error)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function render_login(?array $flash): void
{
    render_auth_start('Giriş');
    ?>
    <main class="auth-card">
        <div class="brand auth-brand"><span class="brand-mark">OP</span><span><strong>Operation</strong><small>Uçuş operasyon yönetimi</small></span></div>
        <?php render_flash($flash); ?>
        <form method="post" class="form-grid single">
            <?= csrf_field() ?>
            <label>Kullanıcı adı veya e-posta<input name="identity" autocomplete="username" required autofocus></label>
            <label>Şifre<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="btn btn-primary">Giriş Yap</button>
        </form>
        <p class="muted auth-note">Hesaplar yalnızca yetkili kullanıcı tarafından oluşturulur.</p>
    </main></body></html>
    <?php
}

function render_auth_start(string $title): void
{
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> · Operation</title><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>"></head><body class="auth-page"><?php
}

function render_page(array $user, string $path, ?array $flash): void
{
    if (is_standard_operation_user($user)) {
        render_operation_page($user, $path, $flash);
        return;
    }
    $title = page_title($path);
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> · Operation</title><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>"></head><body>
    <div class="app-shell"><aside class="sidebar" id="sidebar"><a href="<?= e(url_for('/')) ?>" class="brand"><span class="brand-mark">OP</span><span><strong>Operation</strong><small><?= e(role_summary($user)) ?></small></span></a><nav class="nav"><?= nav_links($user, $path) ?></nav></aside>
    <div class="main-shell"><header class="topbar"><button type="button" class="menu-button" data-menu aria-controls="sidebar" aria-expanded="false">☰</button><div><p class="eyebrow"><?= e(scope_summary($user)) ?></p><h1><?= e($title) ?></h1></div><div class="user-box"><span><strong><?= e($user['name']) ?></strong><small><?= e($user['email'] ?: $user['username']) ?></small></span><form method="post" action="<?= e(url_for('/logout')) ?>"><?= csrf_field() ?><button class="btn btn-ghost">Çıkış</button></form></div></header>
    <?php render_flash($flash); ?><main class="content"><?php render_route($user, $path); ?></main></div></div><script src="<?= e(asset_url('assets/js/app.js')) ?>"></script></body></html><?php
}

function render_operation_page(array $user, string $path, ?array $flash): void
{
    $title = $path === '/' ? 'Görevlerim' : page_title($path);
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title><?= e($title) ?> · Operation</title><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>"></head><body class="operation-mobile-page">
    <div class="task-mobile-shell">
        <header class="task-topbar">
            <a href="<?= e(url_for('/')) ?>" class="task-brand"><span class="brand-mark">OP</span><span><strong>Operation</strong><small><?= e($user['name']) ?></small></span></a>
            <form method="post" action="<?= e(url_for('/logout')) ?>"><?= csrf_field() ?><button class="btn btn-ghost">Çıkış Yap</button></form>
        </header>
        <?php render_flash($flash); ?>
        <main class="task-content"><?php render_route($user, $path); ?></main>
    </div>
    <script src="<?= e(asset_url('assets/js/app.js')) ?>"></script></body></html><?php
}

function page_title(string $path): string
{
    return [
        '/' => 'Genel Bakış', '/users' => 'Kullanıcılar', '/users/edit' => 'Kullanıcı Düzenle', '/roles' => 'Rol ve Yetkiler',
        '/airlines' => 'Havayolları ve ICAO', '/flights' => 'Uçuşlar', '/flights/edit' => 'Uçuş Bilgileri', '/flights/assign' => 'Uçuş Atama',
        '/imports' => 'Uçuş Ekle', '/imports/review' => 'Uçuş Ekle · Önizleme', '/flight' => 'Uçuş Operasyonu', '/timeline' => 'Uçuş Zaman Çizelgesi', '/reports' => 'Raporlar', '/audit' => 'İşlem Kayıtları', '/profile' => 'Profilim',
    ][$path] ?? 'Operation';
}

function nav_links(array $user, string $path): string
{
    $links = ['/'=>'Genel Bakış'];
    if (can($user, 'flights.view')) $links['/flights'] = 'Uçuşlar';
    if (can($user, 'timeline.view')) $links['/timeline'] = 'Uçuş Zaman Çizelgesi';
    if (Authorization::canAny($user, ['imports.view', 'imports.stage', 'imports.commit'])) $links['/imports'] = 'Uçuş Ekle';
    if (can($user, 'users.view')) $links['/users'] = 'Kullanıcılar';
    if (can($user, 'roles.view')) $links['/roles'] = 'Rol ve Yetkiler';
    if (Authorization::canAny($user, ['airlines.view', 'airlines.manage'])) $links['/airlines'] = 'Havayolları / ICAO';
    if (can($user, 'reports.view')) $links['/reports'] = 'Raporlar';
    if (can($user, 'audit.view')) $links['/audit'] = 'İşlem Kayıtları';
    $links['/profile'] = 'Profilim';
    $html = '';
    foreach ($links as $href => $label) {
        $active = $path === $href || ($href !== '/' && str_starts_with($path, $href . '/'));
        $html .= '<a class="' . ($active ? 'active' : '') . '" href="' . e(url_for($href)) . '">' . e($label) . '</a>';
    }
    return $html;
}

function render_route(array $user, string $path): void
{
    switch ($path) {
        case '/':
            Authorization::require($user, 'dashboard.view');
            if (is_standard_operation_user($user)) render_operation_home($user);
            else render_dashboard($user);
            break;
        case '/users': Authorization::require($user, 'users.view'); render_users($user); break;
        case '/users/edit': Authorization::require($user, isset($_GET['id']) ? 'users.update' : 'users.create'); render_user_edit($user); break;
        case '/roles': Authorization::require($user, 'roles.view'); render_roles($user); break;
        case '/airlines': Authorization::require($user, can($user, 'airlines.view') ? 'airlines.view' : 'airlines.manage'); render_airlines($user); break;
        case '/flights': Authorization::require($user, 'flights.view'); render_flights($user); break;
        case '/flights/edit': render_flight_edit($user); break;
        case '/flights/assign': render_flight_assign($user); break;
        case '/imports':
            if (!Authorization::canAny($user, ['imports.view', 'imports.stage', 'imports.commit'])) {
                throw new RuntimeException('Bu işlem için yetkiniz yok.');
            }
            render_imports($user);
            break;
        case '/imports/review':
            if (!Authorization::canAny($user, ['imports.view', 'imports.stage', 'imports.commit'])) {
                throw new RuntimeException('Bu işlem için yetkiniz yok.');
            }
            render_import_review($user);
            break;
        case '/flight': render_flight_detail($user); break;
        case '/timeline': Authorization::require($user, 'timeline.view'); render_timeline($user); break;
        case '/reports': Authorization::require($user, 'reports.view'); render_reports($user); break;
        case '/audit': Authorization::require($user, 'audit.view'); render_audit(); break;
        case '/profile': render_profile($user); break;
        default: http_response_code(404); echo '<section class="panel empty"><h2>Sayfa bulunamadı</h2></section>';
    }
}

function render_dashboard(array $user): void
{
    $flights = FlightService::allVisible($user, ['status' => 'active']);
    $airlines = [];
    $assigned = 0;
    foreach ($flights as $flight) {
        $airlines[$flight['icao_code']] = true;
        if (!empty($flight['assignee_name'])) $assigned++;
    }
    ?><section class="welcome-card"><div><p class="eyebrow">Hoş geldiniz</p><h2><?= e($user['name']) ?></h2><p>Menü ve uçuş görünürlüğü rolünüz, özel yetkileriniz ve ICAO kapsamınıza göre oluşturuldu.</p></div></section>
    <section class="grid cards-4"><div class="metric"><span>Devam eden operasyon</span><strong><?= count($flights) ?></strong></div><div class="metric"><span>Sorumlusu atanmış</span><strong><?= $assigned ?></strong></div><div class="metric"><span>Aktif ICAO</span><strong><?= count($airlines) ?></strong></div><div class="metric accent"><span>Kapsam</span><strong><?= e(scope_summary($user)) ?></strong></div></section>
    <section class="panel"><div class="section-heading"><div><p class="eyebrow">Canlı operasyon</p><h2>Devam eden uçuşlar</h2><p class="muted">Bu ekranda yalnızca sorumlusu tarafından başlatılmış operasyonlar görünür.</p></div></div></section>
    <?php render_flight_table($flights, $user); ?>
    <?php
}

function render_timeline(array $actor): void
{
    $date = TimelineService::normalizeDate((string)($_GET['date'] ?? date('Y-m-d')));
    $dateObject = new DateTimeImmutable($date);
    $previousDate = $dateObject->modify('-1 day')->format('Y-m-d');
    $nextDate = $dateObject->modify('+1 day')->format('Y-m-d');
    $today = date('Y-m-d');
    $canManage = UserService::isAdmin((int)$actor['id']) && can($actor, 'timeline.manage');
    $flightTypes = DB::fetchAll('SELECT id, name FROM flight_types WHERE status = "active" ORDER BY id');
    $timelineUsers = can($actor, 'flights.assign')
        ? DB::fetchAll('SELECT id, username, first_name, last_name FROM users WHERE status = "active" AND deleted_at IS NULL ORDER BY first_name, last_name')
        : [];
    ?>
    <section class="panel timeline-intro">
        <div class="section-heading">
            <div><p class="eyebrow">Günlük operasyon panosu</p><h2><?= e($dateObject->format('d.m.Y')) ?></h2><p class="muted">Operasyon memuru bazında ETA/ETD öncelikli canlı takip. Veriler 15 saniyede bir yenilenir.</p></div>
            <div class="timeline-legend" aria-label="Uçuş durumları"><span class="scheduled">Planlanan</span><span class="active">Devam ediyor</span><span class="completed">Tamamlanan</span><span class="cancelled">İptal</span></div>
        </div>
    </section>
    <section class="panel timeline-toolbar" aria-label="Zaman çizelgesi araçları">
        <div class="timeline-date-tools">
            <a class="btn btn-small" href="<?= e(url_for('/timeline') . '?date=' . $previousDate) ?>" aria-label="Önceki gün">←</a>
            <form method="get" action="<?= e(url_for('/timeline')) ?>" class="timeline-date-form"><input type="date" name="date" value="<?= e($date) ?>" aria-label="Çizelge tarihi"><button class="btn btn-small">Git</button></form>
            <a class="btn btn-small" href="<?= e(url_for('/timeline') . '?date=' . $nextDate) ?>" aria-label="Sonraki gün">→</a>
            <a class="btn btn-ghost btn-small" href="<?= e(url_for('/timeline') . '?date=' . $today) ?>">Bugün</a>
        </div>
        <div class="timeline-filter-tools" aria-label="Zaman çizelgesi filtreleri">
            <label>Uçuş durumu
                <select data-timeline-flight-status>
                    <option value="">Tüm uçuşlar</option>
                    <option value="scheduled">Planlanan</option>
                    <option value="active">Devam ediyor</option>
                    <option value="completed">Tamamlanan</option>
                    <option value="cancelled">İptal</option>
                </select>
            </label>
            <label>Süreç durumu
                <select data-timeline-process-status title="Seçilen durumda en az bir süreci bulunan uçuşları gösterir">
                    <option value="">Tüm süreçler</option>
                    <option value="not_started">Başlamadı</option>
                    <option value="started">Devam ediyor</option>
                    <option value="finished">Tamamlandı</option>
                    <option value="not_used">Kullanılmadı</option>
                </select>
            </label>
            <span class="timeline-filter-count" data-timeline-filter-count aria-live="polite">Uçuşlar yükleniyor…</span>
        </div>
        <div class="timeline-view-tools">
            <button type="button" class="btn btn-ghost btn-small" data-timeline-now>Şimdiye Git</button>
            <button type="button" class="btn btn-ghost btn-small" data-timeline-refresh>Yenile</button>
            <button type="button" class="btn btn-ghost btn-small" data-timeline-focus aria-pressed="false">⛶ Tam Ekran</button>
            <span class="timeline-zoom-controls" aria-label="Yakınlaştırma">
                <button type="button" class="btn btn-small" data-timeline-zoom-out aria-label="Uzaklaştır">−</button>
                <span data-timeline-zoom-label>Sığdır</span>
                <button type="button" class="btn btn-small" data-timeline-zoom-in aria-label="Yakınlaştır">+</button>
            </span>
            <span class="timeline-updated" data-timeline-updated aria-live="polite">Hazırlanıyor…</span>
        </div>
    </section>
    <section class="timeline-root" id="timeline-board"
             data-timeline-root
             data-timeline-date="<?= e($date) ?>"
             data-timeline-data-url="<?= e(url_for('/timeline/data')) ?>">
        <div class="panel timeline-feedback" data-timeline-feedback>Uçuşlar yükleniyor…</div>
        <div class="timeline-scroll panel" data-timeline-scroll tabindex="0" aria-label="Günlük uçuş zaman çizelgesi">
            <div class="timeline-canvas" data-timeline-canvas></div>
        </div>
        <section class="panel timeline-missing" data-timeline-missing hidden><div class="section-heading"><div><p class="eyebrow">Kontrol gerekli</p><h2>Zaman bilgisi eksik uçuşlar</h2></div></div><div class="timeline-missing-list" data-timeline-missing-list></div></section>
    </section>

    <div class="timeline-drawer-layer" data-timeline-drawer-layer hidden>
        <button type="button" class="timeline-drawer-backdrop" data-timeline-drawer-close aria-label="Paneli kapat"></button>
        <aside class="timeline-drawer" data-timeline-drawer role="dialog" aria-modal="true" aria-hidden="true" aria-label="Uçuş düzenleme paneli">
            <header class="timeline-drawer-header"><div><p class="eyebrow">Canlı uçuş</p><h2 data-timeline-drawer-title>Uçuş detayı</h2><p class="muted" data-timeline-drawer-meta></p></div><button type="button" class="btn btn-small" data-timeline-drawer-close aria-label="Kapat">✕</button></header>
            <div class="timeline-drawer-feedback" data-timeline-drawer-feedback hidden></div>
            <section class="timeline-drawer-section"><h3>Operasyon süreçleri</h3><div class="timeline-drawer-processes" data-timeline-drawer-processes></div></section>
            <form method="post" action="<?= e(url_for('/timeline')) ?>" class="timeline-drawer-form" data-timeline-flight-form>
                <input type="hidden" name="_timeline_ajax" value="1"><input type="hidden" name="action" value="save_timeline_flight"><input type="hidden" name="timeline_date" value="<?= e($date) ?>"><input type="hidden" name="flight_id"><input type="hidden" name="airline_id"><input type="hidden" name="status"><?= csrf_field() ?>
                <div class="timeline-drawer-grid">
                    <label>Uçuş tipi<select name="flight_type_id" required><?php options_rows($flightTypes, 0, 'name'); ?></select></label>
                    <label>Park<input name="stand"></label>
                    <label>Arrival uçuş no<input name="arrival_flight_number"></label><label>Departure uçuş no<input name="departure_flight_number"></label>
                    <label>Arrival origin<input name="arrival_origin"></label><label>Arrival destination<input name="arrival_destination"></label>
                    <label>Departure origin<input name="departure_origin"></label><label>Departure destination<input name="departure_destination"></label>
                    <label>STA<input type="datetime-local" name="scheduled_arrival_at"></label><label>ETA<input type="datetime-local" name="estimated_arrival_at"></label>
                    <label>STD<input type="datetime-local" name="scheduled_departure_at"></label><label>ETD<input type="datetime-local" name="estimated_departure_at"></label>
                    <label>Kuyruk no<input name="tail_number"></label><label>Uçak tipi<input name="aircraft_type"></label>
                    <label class="full">Not<textarea name="note" rows="2"></textarea></label>
                </div>
                <p class="timeline-drawer-readonly muted" data-timeline-drawer-readonly hidden>Bu uçuşta bilgi düzenleme yetkiniz yok.</p>
                <button class="btn btn-primary" data-timeline-flight-save>Uçuş Bilgilerini Kaydet</button>
            </form>
            <?php if ($timelineUsers): ?>
                <form method="post" action="<?= e(url_for('/timeline')) ?>" class="timeline-drawer-assign" data-timeline-assign-form>
                    <input type="hidden" name="_timeline_ajax" value="1"><input type="hidden" name="action" value="assign_timeline_flight"><input type="hidden" name="timeline_date" value="<?= e($date) ?>"><input type="hidden" name="flight_id"><?= csrf_field() ?>
                    <label>Operasyon memuru<select name="user_id"><option value="0">Atanmamış</option><?php foreach ($timelineUsers as $timelineUser): ?><option value="<?= (int)$timelineUser['id'] ?>"><?= e(trim($timelineUser['first_name'] . ' ' . $timelineUser['last_name']) . ' · ' . $timelineUser['username']) ?></option><?php endforeach; ?></select></label>
                    <button class="btn btn-success">Atamayı Kaydet</button>
                    <small data-timeline-assign-note>Atama yalnızca planlanan uçuşlarda değiştirilebilir.</small>
                </form>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <form method="post" action="<?= e(url_for('/timeline')) ?>" class="timeline-drawer-status" data-timeline-status-form data-confirm="Uçuş durumu değiştirilecek; süreç kayıtları korunacak. Devam edilsin mi?" hidden>
                    <input type="hidden" name="_timeline_ajax" value="1"><input type="hidden" name="action" value="change_timeline_flight_status"><input type="hidden" name="timeline_date" value="<?= e($date) ?>"><input type="hidden" name="flight_id"><?= csrf_field() ?>
                    <label>Uçuş durumu<select name="target_status" required></select></label>
                    <button class="btn btn-warning">Durumu Değiştir</button>
                    <small>Süreç kayıtları korunur. Devam eden uçuş planlanana alınırsa mevcut atama kaldırılır.</small>
                </form>
            <?php endif; ?>
        </aside>
    </div>

    <?php if ($canManage):
        $settings = TimelineService::settings();
        $airlines = DB::fetchAll('SELECT id, icao_code, name FROM airlines WHERE status = "active" ORDER BY icao_code');
        $selectedAirlineId = (int)($_GET['airline_id'] ?? ($airlines[0]['id'] ?? 0));
        $validAirlineIds = array_map('intval', array_column($airlines, 'id'));
        if (!in_array($selectedAirlineId, $validAirlineIds, true)) $selectedAirlineId = (int)($airlines[0]['id'] ?? 0);
        $rules = $selectedAirlineId > 0 ? TimelineService::ruleRows($actor, $selectedAirlineId) : [];
        ?>
        <section class="panel timeline-rules" id="timeline-rules">
            <div class="section-heading"><div><p class="eyebrow">Admin ayarları</p><h2>Süre Kuralları</h2><p class="muted">Firma ve uçak tipi kuralı bulunamazsa global süreler kullanılır. Değişiklikler çizelgeye anında yansır.</p></div></div>
            <form method="post" class="timeline-default-form">
                <input type="hidden" name="action" value="save_timeline_defaults"><input type="hidden" name="timeline_date" value="<?= e($date) ?>"><input type="hidden" name="timeline_airline_id" value="<?= $selectedAirlineId ?>"><?= csrf_field() ?>
                <label>Global Arrival süresi<input type="number" name="default_arrival_minutes" min="5" max="720" value="<?= (int)$settings['default_arrival_minutes'] ?>" required><small>Dakika · ETA/STA sonrasına eklenir</small></label>
                <label>Global Departure süresi<input type="number" name="default_departure_minutes" min="5" max="720" value="<?= (int)$settings['default_departure_minutes'] ?>" required><small>Dakika · ETD/STD öncesinden başlar</small></label>
                <button class="btn btn-primary">Global Süreleri Kaydet</button>
            </form>
            <?php if ($airlines): ?>
                <form method="get" action="<?= e(url_for('/timeline')) ?>" class="timeline-airline-picker"><input type="hidden" name="date" value="<?= e($date) ?>"><label>Firma<select name="airline_id"><?php options_rows($airlines, $selectedAirlineId, 'icao_code', 'name'); ?></select></label><button class="btn">Kuralları Göster</button></form>
                <div class="timeline-rule-list">
                    <?php foreach ($rules as $rule): ?>
                        <div class="timeline-rule-row">
                            <form method="post" class="timeline-rule-form">
                                <input type="hidden" name="action" value="save_timeline_rule"><input type="hidden" name="timeline_date" value="<?= e($date) ?>"><input type="hidden" name="timeline_airline_id" value="<?= $selectedAirlineId ?>"><input type="hidden" name="airline_id" value="<?= $selectedAirlineId ?>"><input type="hidden" name="aircraft_type" value="<?= e($rule['aircraft_type']) ?>"><?= csrf_field() ?>
                                <label>Uçak tipi<input value="<?= e($rule['aircraft_type']) ?>" disabled><?php if (!$rule['has_rule']): ?><small>Şu an global varsayılanı kullanıyor</small><?php endif; ?></label>
                                <label>Arrival dk<input type="number" name="arrival_minutes" min="5" max="720" value="<?= (int)$rule['arrival_minutes'] ?>" required></label>
                                <label>Departure dk<input type="number" name="departure_minutes" min="5" max="720" value="<?= (int)$rule['departure_minutes'] ?>" required></label>
                                <button class="btn btn-small btn-primary"><?= $rule['has_rule'] ? 'Güncelle' : 'Kural Oluştur' ?></button>
                            </form>
                            <?php if ($rule['has_rule']): ?><form method="post" data-confirm="Bu uçak tipi kuralı silinecek ve global varsayılan kullanılacak. Devam edilsin mi?"><input type="hidden" name="action" value="delete_timeline_rule"><input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>"><input type="hidden" name="timeline_date" value="<?= e($date) ?>"><input type="hidden" name="timeline_airline_id" value="<?= $selectedAirlineId ?>"><?= csrf_field() ?><button class="btn btn-danger btn-small">Sil</button></form><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$rules): ?><div class="empty">Bu firma için sistemde uçak tipi bulunamadı. Aşağıdan manuel ekleyebilirsiniz.</div><?php endif; ?>
                </div>
                <form method="post" class="timeline-rule-form timeline-rule-new">
                    <input type="hidden" name="action" value="save_timeline_rule"><input type="hidden" name="timeline_date" value="<?= e($date) ?>"><input type="hidden" name="timeline_airline_id" value="<?= $selectedAirlineId ?>"><input type="hidden" name="airline_id" value="<?= $selectedAirlineId ?>"><?= csrf_field() ?>
                    <label>Yeni uçak tipi<input name="aircraft_type" maxlength="20" placeholder="Örn. A320" required></label>
                    <label>Arrival dk<input type="number" name="arrival_minutes" min="5" max="720" value="<?= (int)$settings['default_arrival_minutes'] ?>" required></label>
                    <label>Departure dk<input type="number" name="departure_minutes" min="5" max="720" value="<?= (int)$settings['default_departure_minutes'] ?>" required></label>
                    <button class="btn btn-success">Yeni Kural Ekle</button>
                </form>
            <?php else: ?><div class="notice">Süre kuralı tanımlamak için önce aktif bir havayolu ekleyin.</div><?php endif; ?>
        </section>
    <?php endif;
}

function render_operation_home(array $user): void
{
    Authorization::require($user, 'flights.view');
    $assigned = [];
    $completed = [];
    foreach (FlightService::assignedTasks($user) as $flight) {
        if ($flight['status'] === 'completed') $completed[] = $flight;
        else $assigned[] = $flight;
    }
    ?>
    <section class="task-welcome">
        <p class="eyebrow">Merhaba <?= e($user['first_name'] ?: $user['username']) ?></p>
        <h1>Görevlerin</h1>
        <p>Atanan uçuşunu aç, operasyonu başlat ve süreçleri buradan takip et.</p>
    </section>
    <nav class="task-tabs" aria-label="Görev listeleri">
        <button type="button" class="task-tab active" data-task-tab="assigned" aria-selected="true">Atanan Görevler <span><?= count($assigned) ?></span></button>
        <button type="button" class="task-tab" data-task-tab="completed" aria-selected="false">Biten Görevler <span><?= count($completed) ?></span></button>
    </nav>
    <section class="task-panel" data-task-panel="assigned">
        <div class="task-list"><?php foreach ($assigned as $flight) render_operation_task_card($flight); ?><?php if (!$assigned): ?><div class="task-empty"><strong>Atanmış görev yok</strong><p>Yeni bir uçuş atandığında burada görünecek.</p></div><?php endif; ?></div>
    </section>
    <section class="task-panel" data-task-panel="completed" hidden>
        <div class="task-list"><?php foreach ($completed as $flight) render_operation_task_card($flight); ?><?php if (!$completed): ?><div class="task-empty"><strong>Bitmiş görev yok</strong><p>Tamamladığın uçuşlar burada listelenecek.</p></div><?php endif; ?></div>
    </section>
    <?php
}

function render_operation_task_card(array $flight): void
{
    $active = $flight['status'] === 'active';
    $completed = $flight['status'] === 'completed';
    $statusLabel = $completed ? 'Tamamlandı' : ($active ? 'Devam ediyor' : 'Başlamadı');
    $actionLabel = $completed ? 'Detayı Gör' : ($active ? 'Devam Et' : 'Görevi Aç');
    ?>
    <article class="task-flight-card status-<?= e($flight['status']) ?>">
        <div class="task-card-heading">
            <div><span class="task-airline-code"><?= e($flight['icao_code']) ?></span><small><?= e($flight['flight_type_name']) ?></small></div>
            <span class="badge <?= e($flight['status']) ?>"><?= e($statusLabel) ?></span>
        </div>
        <div class="task-flight-numbers"><strong><?= e($flight['arrival_flight_number'] ?: '-') ?></strong><span>→</span><strong><?= e($flight['departure_flight_number'] ?: '-') ?></strong></div>
        <div class="task-route-grid">
            <div><small>G1 Arrival</small><strong><?= e($flight['arrival_origin'] ?: '-') ?></strong></div>
            <div><small>G1 Departure</small><strong><?= e($flight['departure_destination'] ?: '-') ?></strong></div>
            <div><small>STA</small><strong><?= e($flight['scheduled_arrival_at'] ? date('d.m H:i', strtotime($flight['scheduled_arrival_at'])) : '-') ?></strong></div>
            <div><small>STD</small><strong><?= e($flight['scheduled_departure_at'] ? date('d.m H:i', strtotime($flight['scheduled_departure_at'])) : '-') ?></strong></div>
        </div>
        <div class="task-card-meta"><span><?= e($flight['tail_number'] ?: 'Uçak -') ?></span><span>Park <?= e($flight['stand'] ?: '-') ?></span></div>
        <a class="btn <?= $active ? 'btn-success' : ($completed ? 'btn-ghost' : 'btn-primary') ?> task-card-action" href="<?= e(url_for('/flight') . '?id=' . (int)$flight['id']) ?>"><?= e($actionLabel) ?></a>
    </article>
    <?php
}

function render_users(array $actor): void
{
    $users = UserService::all();
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= count($users) ?> aktif/pasif kayıt</p><h2>Kullanıcı ve kapsam yönetimi</h2><p class="muted">Operation atanmış uçuşlarla; supervisor seçilen tekli veya çoklu ICAO kodlarıyla çalışır.</p></div><?php if (can($actor, 'users.create')): ?><a class="btn btn-primary" href="<?= e(url_for('/users/edit')) ?>">Yeni Kullanıcı</a><?php endif; ?></div></section>
    <section class="panel table-wrap"><table><thead><tr><th>Ad soyad</th><th>Kullanıcı</th><th>Rol</th><th>ICAO kapsamı</th><th>Durum</th><th>Son giriş</th><th></th></tr></thead><tbody><?php foreach ($users as $item): ?><tr><td><strong><?= e($item['first_name'] . ' ' . $item['last_name']) ?></strong><small><?= e($item['email'] ?: '-') ?></small></td><td><?= e($item['username']) ?></td><td><?= e($item['roles'] ?: '-') ?></td><td><?= e($item['icao_scopes'] ?: '-') ?></td><td><span class="badge <?= e($item['status']) ?>"><?= e($item['status']) ?></span></td><td><?= e($item['last_login_at'] ? date('d.m.Y H:i', strtotime($item['last_login_at'])) : '-') ?></td><td><a class="btn btn-small" href="<?= e(url_for('/users/edit') . '?id=' . (int)$item['id']) ?>">Düzenle</a></td></tr><?php endforeach; ?></tbody></table></section><?php
}

function render_user_edit(array $actor): void
{
    $userId = (int)($_GET['id'] ?? 0);
    $target = $userId ? UserService::find($userId) : null;
    if ($userId && !$target) throw new RuntimeException('Kullanıcı bulunamadı.');
    $access = $userId ? UserService::access($userId) : ['roles'=>['operation'], 'airline_ids'=>[], 'overrides'=>[]];
    $isAdmin = $userId && UserService::isAdmin($userId);
    $airlines = DB::fetchAll('SELECT id, icao_code, name FROM airlines WHERE status = "active" ORDER BY icao_code');
    $permissions = DB::fetchAll('SELECT id, code, name FROM permissions WHERE code IN ("flights.view", "flights.update", "flights.cancel", "flights.delete", "flights.assign", "flights.complete", "processes.view", "processes.update", "reports.view") ORDER BY code');
    $allowIds = []; $denyIds = [];
    foreach ($access['overrides'] as $override) { if ($override['effect'] === 'allow') $allowIds[] = (int)$override['permission_id']; else $denyIds[] = (int)$override['permission_id']; }
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= $target ? 'Kullanıcı #' . (int)$target['id'] : 'Yeni hesap' ?></p><h2><?= e($target ? $target['first_name'] . ' ' . $target['last_name'] : 'Kullanıcı oluştur') ?></h2></div><a class="btn btn-ghost" href="<?= e(url_for('/users')) ?>">Listeye dön</a></div>
    <form method="post" class="form-grid"><input type="hidden" name="action" value="save_user"><input type="hidden" name="user_id" value="<?= $userId ?>"><?= csrf_field() ?>
    <label>Ad<input name="first_name" value="<?= e($target['first_name'] ?? '') ?>" required></label><label>Soyad<input name="last_name" value="<?= e($target['last_name'] ?? '') ?>" required></label><label>Kullanıcı adı<input name="username" value="<?= e($target['username'] ?? '') ?>" required></label><label>E-posta<input type="email" name="email" value="<?= e($target['email'] ?? '') ?>"></label><label>Durum<select name="status"><option value="active" <?= selected($target['status'] ?? 'active', 'active') ?>>Aktif</option><option value="inactive" <?= selected($target['status'] ?? '', 'inactive') ?>>Pasif</option></select></label><label><?= $target ? 'Yeni geçici şifre (opsiyonel)' : 'Geçici şifre' ?><input type="password" name="password" minlength="12" <?= $target ? '' : 'required' ?>></label>
    <?php if ($isAdmin): ?><div class="notice full">Tek global admin hesabının rolü, kapsamı ve aktif durumu kilitlidir.</div><?php else: ?>
    <fieldset><legend>Roller</legend><label class="check-label"><input type="checkbox" name="roles[]" value="operation" <?= checked(in_array('operation', $access['roles'], true)) ?>><span>Operation – atanmış uçuşlar</span></label><label class="check-label"><input type="checkbox" name="roles[]" value="supervisor" <?= checked(in_array('supervisor', $access['roles'], true)) ?>><span>Supervisor – ICAO kapsamı</span></label></fieldset>
    <fieldset><legend>Supervisor ICAO kapsamı</legend><?php foreach ($airlines as $airline): ?><label class="check-label"><input type="checkbox" name="airline_ids[]" value="<?= (int)$airline['id'] ?>" <?= checked(in_array((int)$airline['id'], $access['airline_ids'], true)) ?>><span><?= e($airline['icao_code'] . ' · ' . $airline['name']) ?></span></label><?php endforeach; ?></fieldset>
    <fieldset class="full"><legend>Kullanıcıya özel yetkiler</legend><div class="permission-table"><div><strong>Yetki</strong><strong>Ek izin</strong><strong>Engelle</strong></div><?php foreach ($permissions as $permission): ?><div><span><code><?= e($permission['code']) ?></code><small><?= e($permission['name']) ?></small></span><input type="checkbox" name="allow_permission_ids[]" value="<?= (int)$permission['id'] ?>" <?= checked(in_array((int)$permission['id'], $allowIds, true)) ?>><input type="checkbox" name="deny_permission_ids[]" value="<?= (int)$permission['id'] ?>" <?= checked(in_array((int)$permission['id'], $denyIds, true)) ?>></div><?php endforeach; ?></div><p class="muted">Supervisor için bütün seçili ICAO kodlarında; yalnız operation kullanıcısında atanmış uçuşlarda uygulanır.</p></fieldset>
    <?php endif; ?><button class="btn btn-primary">Kaydet</button></form></section>
    <?php if ($target && !$isAdmin && (int)$actor['id'] !== $userId && can($actor, 'users.delete')): ?><section class="panel danger-zone"><h2>Kullanıcıyı sil</h2><p>Geçmiş kaydı varsa hesap anonimleştirilir.</p><form method="post" data-confirm="Kullanıcı silinecek veya geçmişi varsa anonimleştirilecek. Devam edilsin mi?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?= $userId ?>"><button class="btn btn-danger">Kullanıcıyı Sil</button></form></section><?php endif;
}

function render_roles(array $actor): void
{
    $groups = Authorization::permissionGroups();
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow">Fizyorez tarzı dinamik erişim</p><h2>Rol yetki matrisi</h2><p class="muted">Admin tüm yetkilere sahiptir. Supervisor ve operation yetkileri buradan yönetilir; uçuş kapsamı ayrıca kullanıcıda ICAO/atama ile sınırlandırılır.</p></div></div></section><div class="role-grid"><?php foreach (Authorization::roles() as $role): $assigned = Authorization::rolePermissionIds((int)$role['id']); $locked = $role['code'] === 'admin' || !can($actor, 'roles.manage'); ?><form method="post" class="panel role-card"><input type="hidden" name="action" value="save_role_permissions"><input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>"><?= csrf_field() ?><h2><?= e($role['name']) ?></h2><p class="muted"><?= e($role['description']) ?></p><?php if ($role['code'] === 'admin'): ?><div class="notice">Tüm yetkiler kilitli ve açık.</div><?php else: foreach ($groups as $group=>$items): ?><fieldset><legend><?= e($group) ?></legend><?php foreach ($items as $permission): ?><label class="check-label"><input type="checkbox" name="permission_ids[]" value="<?= (int)$permission['id'] ?>" <?= checked(in_array((int)$permission['id'], $assigned, true)) ?> <?= $locked ? 'disabled' : '' ?>><span><?= e($permission['name']) ?></span></label><?php endforeach; ?></fieldset><?php endforeach; if (!$locked): ?><button class="btn btn-primary">Yetkileri Kaydet</button><?php endif; endif; ?></form><?php endforeach; ?></div><?php
}

function render_airlines(array $actor): void
{
    $airlines = DB::fetchAll('SELECT * FROM airlines ORDER BY icao_code');
    if (can($actor, 'airlines.manage')): ?><section class="panel"><h2>Havayolu ekle</h2><form method="post" class="form-grid"><input type="hidden" name="action" value="save_airline"><input type="hidden" name="airline_id" value="0"><?= csrf_field() ?><label>Ad<input name="name" required></label><label>ICAO<input name="icao_code" maxlength="3" required></label><label>IATA<input name="iata_code" maxlength="2"></label><input type="hidden" name="status" value="active"><button class="btn btn-primary">Ekle</button></form></section><?php endif; ?>
    <section class="panel table-wrap"><table><thead><tr><th>ICAO</th><th>IATA</th><th>Ad</th><th>Durum</th><th></th></tr></thead><tbody><?php foreach ($airlines as $airline): $formId = 'airline-' . (int)$airline['id']; ?><tr><td><input form="<?= e($formId) ?>" name="icao_code" maxlength="3" value="<?= e($airline['icao_code']) ?>" <?= can($actor, 'airlines.manage') ? '' : 'disabled' ?>></td><td><input form="<?= e($formId) ?>" name="iata_code" maxlength="2" value="<?= e($airline['iata_code']) ?>" <?= can($actor, 'airlines.manage') ? '' : 'disabled' ?>></td><td><input form="<?= e($formId) ?>" name="name" value="<?= e($airline['name']) ?>" <?= can($actor, 'airlines.manage') ? '' : 'disabled' ?>></td><td><select form="<?= e($formId) ?>" name="status" <?= can($actor, 'airlines.manage') ? '' : 'disabled' ?>><option value="active" <?= selected($airline['status'], 'active') ?>>Aktif</option><option value="inactive" <?= selected($airline['status'], 'inactive') ?>>Pasif</option></select></td><td><?php if (can($actor, 'airlines.manage')): ?><form method="post" id="<?= e($formId) ?>"><input type="hidden" name="action" value="save_airline"><input type="hidden" name="airline_id" value="<?= (int)$airline['id'] ?>"><?= csrf_field() ?><button class="btn btn-small">Kaydet</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section><?php
}

function render_flights(array $actor): void
{
    $status = trim((string)($_GET['status'] ?? ''));
    $flights = FlightService::allVisible($actor, ['status'=>$status]);
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= count($flights) ?> uçuş</p><h2>Yetki kapsamındaki uçuşlar</h2><p class="muted">Bu ekran mevcut uçuşları takip etmek içindir. Yeni uçuşlar Uçuş Ekle menüsünden Excel ile aktarılır.</p></div></div><nav class="tabs"><?php foreach ([''=>'Tümü','scheduled'=>'Planlanan','active'=>'Devam Eden','completed'=>'Tamamlanan','cancelled'=>'İptal'] as $value=>$label): ?><a class="<?= $status===$value?'active':'' ?>" href="<?= e(url_for('/flights') . ($value ? '?status='.$value : '')) ?>"><?= e($label) ?></a><?php endforeach; ?></nav></section><?php render_flight_table($flights, $actor);
}

function render_flight_table(array $flights, array $actor): void
{
    ?><section class="panel table-wrap"><table class="flight-table"><thead><tr><th>ICAO</th><th>Arrival sefer</th><th>G1 Arrival</th><th>Departure sefer</th><th>G1 Departure</th><th>STA / STD</th><th>Tip</th><th>Uçak / Park</th><th>Sorumlu</th><th>Durum</th><th></th></tr></thead><tbody><?php foreach ($flights as $flight): $context=FlightService::context($flight); ?><tr><td><strong><?= e($flight['icao_code']) ?></strong><small><?= e($flight['airline_name']) ?></small></td><td><strong><?= e($flight['arrival_flight_number'] ?: '-') ?></strong></td><td><strong><?= e($flight['arrival_origin'] ?: '-') ?></strong></td><td><strong><?= e($flight['departure_flight_number'] ?: '-') ?></strong></td><td><strong><?= e($flight['departure_destination'] ?: '-') ?></strong></td><td><strong>STA <?= e($flight['scheduled_arrival_at'] ? date('d.m H:i', strtotime($flight['scheduled_arrival_at'])) : '-') ?></strong><small>STD <?= e($flight['scheduled_departure_at'] ? date('d.m H:i', strtotime($flight['scheduled_departure_at'])) : '-') ?></small></td><td><?= e($flight['flight_type_name']) ?></td><td><?= e($flight['tail_number'] ?: '-') ?><small>Park <?= e($flight['stand'] ?: '-') ?></small></td><td><?= e($flight['assignee_name'] ?: 'Atanmamış') ?></td><td><span class="badge <?= e($flight['status']) ?>"><?= e(flight_status_label($flight['status'])) ?></span></td><td class="actions"><a class="btn btn-small" href="<?= e(url_for('/flight').'?id='.(int)$flight['id']) ?>">Aç</a><?php if (can($actor,'flights.update',$context)): ?><a class="btn btn-small" href="<?= e(url_for('/flights/edit').'?id='.(int)$flight['id']) ?>">Düzenle</a><?php endif; ?><?php if (can($actor,'flights.assign',$context)): ?><a class="btn btn-small" href="<?= e(url_for('/flights/assign').'?id='.(int)$flight['id']) ?>">Ata</a><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$flights):?><tr><td colspan="11" class="empty">Uçuş bulunmuyor.</td></tr><?php endif;?></tbody></table></section><?php
}

function render_flight_edit(array $actor): void
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) redirect_to('/imports');
    $flight = FlightService::find($id);
    if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
    Authorization::require($actor, 'flights.update', FlightService::context($flight));
    $permission = 'flights.update';
    $airlines = array_values(array_filter(
        DB::fetchAll('SELECT id, icao_code, name FROM airlines WHERE status="active" ORDER BY icao_code'),
        static function (array $airline) use ($actor, $flight, $permission): bool {
            if ((int)$flight['airline_id'] === (int)$airline['id']) return true;
            return can($actor, $permission, ['airline_id' => (int)$airline['id']]);
        }
    ));
    $types = DB::fetchAll('SELECT id, name FROM flight_types WHERE status="active" ORDER BY id');
    $isAdmin = UserService::isAdmin((int)$actor['id']);
    ?>
    <section class="panel">
        <div class="section-heading"><div><p class="eyebrow">Uçuş #<?= $id ?></p><h2>Uçuş bilgileri</h2></div><a class="btn btn-ghost" href="<?= e(url_for('/flights')) ?>">Listeye dön</a></div>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="status" value="<?= e($flight['status']) ?>"><?= csrf_field() ?>
            <label>Havayolu<select name="airline_id" required><?php options_rows($airlines, (int)$flight['airline_id'], 'icao_code', 'name'); ?></select></label>
            <label>Uçuş tipi<select name="flight_type_id" required><?php options_rows($types, (int)$flight['flight_type_id'], 'name'); ?></select></label>
            <label>Arrival uçuş no<input name="arrival_flight_number" value="<?= e($flight['arrival_flight_number']) ?>"></label><label>Departure uçuş no<input name="departure_flight_number" value="<?= e($flight['departure_flight_number']) ?>"></label>
            <label>Arrival origin<input name="arrival_origin" value="<?= e($flight['arrival_origin']) ?>"></label><label>Arrival destination<input name="arrival_destination" value="<?= e($flight['arrival_destination']) ?>"></label>
            <label>Departure origin<input name="departure_origin" value="<?= e($flight['departure_origin']) ?>"></label><label>Departure destination<input name="departure_destination" value="<?= e($flight['departure_destination']) ?>"></label>
            <label>STA<input type="datetime-local" name="scheduled_arrival_at" value="<?= e(datetime_local($flight['scheduled_arrival_at'])) ?>"></label><label>ETA<input type="datetime-local" name="estimated_arrival_at" value="<?= e(datetime_local($flight['estimated_arrival_at'])) ?>"></label>
            <label>STD<input type="datetime-local" name="scheduled_departure_at" value="<?= e(datetime_local($flight['scheduled_departure_at'])) ?>"></label><label>ETD<input type="datetime-local" name="estimated_departure_at" value="<?= e(datetime_local($flight['estimated_departure_at'])) ?>"></label>
            <label>Kuyruk no<input name="tail_number" value="<?= e($flight['tail_number']) ?>"></label><label>Uçak tipi<input name="aircraft_type" value="<?= e($flight['aircraft_type']) ?>"></label><label>Park<input name="stand" value="<?= e($flight['stand']) ?>"></label>
            <div><p class="muted">Durum</p><span class="badge <?= e($flight['status']) ?>"><?= e(flight_status_label($flight['status'])) ?></span></div>
            <label class="full">Not<textarea name="note" rows="3"><?= e($flight['note']) ?></textarea></label><button class="btn btn-primary">Bilgileri Kaydet</button>
        </form>
    </section>
    <?php if ($isAdmin && $flight['status'] === 'completed'): ?>
        <section class="panel status-restore"><h2>Tamamlanan uçuşun durumunu değiştir</h2><p class="muted">Süreç kayıtları aynen korunur ve sıfırlanmaz. Planlanan seçildiğinde sorumlu atama ekranı açılır; mevcut kullanıcıyı bırakabilir veya başka kullanıcı seçebilirsin.</p><form method="post" class="inline-form" data-confirm="Tamamlanan uçuş seçilen duruma geri alınacak; süreç kayıtları korunacak. Devam edilsin mi?"><input type="hidden" name="action" value="restore_flight_status"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><label>Yeni durum<select name="target_status" required><option value="scheduled">Planlanan</option><option value="active">Devam ediyor</option><option value="cancelled">İptal</option></select></label><button class="btn btn-warning">Durumu Kaydet</button></form></section>
    <?php elseif ($isAdmin && $flight['status'] === 'active'): ?>
        <section class="panel status-restore"><h2>Yanlışlıkla başlatılan uçuş</h2><p class="muted">Uçuş planlanan duruma döner ve mevcut kullanıcı ataması kaldırılır. Süreç kayıtları aynen korunur; sonraki ekranda yeni sorumluyu seçebilirsin.</p><form method="post" data-confirm="Devam eden uçuş planlanan duruma alınacak, mevcut kullanıcıdan kaldırılacak ve süreç kayıtları korunacak. Devam edilsin mi?"><input type="hidden" name="action" value="restore_flight_status"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="target_status" value="scheduled"><?= csrf_field() ?><button class="btn btn-warning">Planlanana Al ve Yeniden Ata</button></form></section>
    <?php endif; ?>
    <?php if (can($actor, 'flights.delete', FlightService::context($flight))): ?><section class="panel danger-zone"><h2>Uçuşu kalıcı sil</h2><p>Uçuş ve bağlı operasyon kayıtları sistemden silinir; yalnızca kısa işlem kaydı tutulur.</p><form method="post" data-confirm="Uçuş kalıcı olarak silinecek. Bu işlem geri alınamaz. Devam edilsin mi?"><input type="hidden" name="action" value="delete_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><button class="btn btn-danger">Uçuşu Sil</button></form></section><?php endif;
}

function render_flight_assign(array $actor): void
{
    $id = (int)($_GET['id'] ?? 0);
    $flight = FlightService::find($id);
    if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
    Authorization::require($actor, 'flights.assign', FlightService::context($flight));
    $users = DB::fetchAll('SELECT id,username,first_name,last_name FROM users WHERE status="active" AND deleted_at IS NULL ORDER BY first_name,last_name');
    $assignment = FlightService::assignments($id);
    $assignedUserId = (int)($assignment[0]['user_id'] ?? 0);
    ?>
    <section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= e($flight['icao_code']) ?> · Uçuş #<?= $id ?></p><h2>Uçuş sorumlusu</h2><p class="muted">Her uçuş aynı anda yalnızca bir aktif kullanıcıya atanır.</p></div><a class="btn btn-ghost" href="<?= e(url_for('/flights')) ?>">Uçuşlara dön</a></div></section>
    <section class="flight-leg-grid">
        <article class="panel flight-leg"><p class="eyebrow">Arrival</p><h2><?= e($flight['arrival_flight_number'] ?: '-') ?></h2><p><?= e(($flight['arrival_origin'] ?: '-') . ' → ' . ($flight['arrival_destination'] ?: '-')) ?></p><small>STA <?= e($flight['scheduled_arrival_at'] ? date('d.m.Y H:i', strtotime($flight['scheduled_arrival_at'])) : '-') ?></small></article>
        <article class="panel flight-leg"><p class="eyebrow">Departure / devam seferi</p><h2><?= e($flight['departure_flight_number'] ?: '-') ?></h2><p><?= e(($flight['departure_origin'] ?: '-') . ' → ' . ($flight['departure_destination'] ?: '-')) ?></p><small>STD <?= e($flight['scheduled_departure_at'] ? date('d.m.Y H:i', strtotime($flight['scheduled_departure_at'])) : '-') ?></small></article>
    </section>
    <?php if (in_array($flight['status'], ['active', 'completed'], true) && UserService::isAdmin((int)$actor['id'])): ?>
    <section class="panel status-restore"><h2>Uçuşu yeniden atamaya aç</h2><p class="muted">Uçuş planlanan duruma alınır ve süreç kayıtları aynen korunur. Devam eden uçuşsa mevcut atama kaldırılır; sonraki ekranda yeni sorumluyu seçebilirsin.</p><form method="post" data-confirm="Uçuş planlanan duruma alınacak ve süreç kayıtları korunacak. Devam edilsin mi?"><input type="hidden" name="action" value="restore_flight_status"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="target_status" value="scheduled"><?= csrf_field() ?><button class="btn btn-warning">Planlanana Al ve Yeniden Ata</button></form></section>
    <?php elseif (in_array($flight['status'], ['completed', 'cancelled'], true)): ?>
    <section class="panel notice">Tamamlanmış veya iptal edilmiş uçuşun sorumlusu değiştirilemez.</section>
    <?php else: ?>
    <section class="panel"><form method="post"><input type="hidden" name="action" value="assign_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><div class="assignment-grid"><label class="assignment-card"><input type="radio" name="user_id" value="0" <?= checked($assignedUserId === 0) ?>><span><strong>Atanmamış</strong><small>Aktif sorumluyu kaldır</small></span></label><?php foreach($users as $item):?><label class="assignment-card"><input type="radio" name="user_id" value="<?= (int)$item['id'] ?>" <?= checked((int)$item['id'] === $assignedUserId) ?>><span><strong><?= e($item['first_name'].' '.$item['last_name']) ?></strong><small><?= e($item['username']) ?></small></span></label><?php endforeach;?></div><button class="btn btn-primary">Sorumluyu Kaydet</button></form></section><?php
    endif;
}

function render_imports(array $actor): void
{
    ImportService::cleanupTransient();
    if (can($actor, 'imports.stage')): ?>
        <section class="panel">
            <div class="section-heading"><div><p class="eyebrow">Excel ile uçuş ekleme</p><h2>Uçuş Excelini yükle</h2><p class="muted">İlk sayfanın A:Q kolonları okunur. Önizleme geçmiş olarak tutulmaz; SQL aktarımında veya vazgeçildiğinde tamamen silinir.</p></div></div>
            <form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="action" value="stage_import"><?= csrf_field() ?><label>XLSX veya CSV<input type="file" name="excel_file" accept=".xlsx,.csv" required></label><button class="btn btn-primary">Önizleme Oluştur</button></form>
            <p class="muted">Yeni dosya yüklemek önceki tamamlanmamış önizlemenizi siler. İki saatten eski geçici önizlemeler otomatik temizlenir.</p>
        </section>
    <?php endif;

    if (can($actor, 'imports.commit')):
        $airlines = array_values(array_filter(
            DB::fetchAll('SELECT id, icao_code, name FROM airlines WHERE status = "active" ORDER BY icao_code'),
            static fn(array $airline): bool => can($actor, 'imports.commit', ['airline_id' => (int)$airline['id']])
        ));
        $types = DB::fetchAll('SELECT id, name FROM flight_types WHERE status = "active" ORDER BY id');
        ?>
        <section class="panel">
            <div class="section-heading"><div><p class="eyebrow">Tek uçuş ekleme</p><h2>Manuel uçuş oluştur</h2><p class="muted">Bu form yalnızca Uçuş Ekle sayfasındadır. Uçuş planlanan durumda kaydedilir ve ardından sorumlu atama ekranı açılır.</p></div></div>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="create_manual_flight"><input type="hidden" name="arrival_destination" value="AYT"><input type="hidden" name="departure_origin" value="AYT"><?= csrf_field() ?>
                <label>Havayolu<select name="airline_id" required><?php options_rows($airlines, 0, 'icao_code', 'name'); ?></select></label>
                <label>Uçuş tipi<select name="flight_type_id" required><?php options_rows($types, 0, 'name'); ?></select></label>
                <label>Arrival uçuş no<input name="arrival_flight_number" placeholder="Örn. XQ129A"></label>
                <label>Departure uçuş no<input name="departure_flight_number" placeholder="Örn. XQ192A"></label>
                <label>G1 Arrival<input name="arrival_origin" maxlength="10" placeholder="Örn. GVA"></label>
                <label>G1 Departure<input name="departure_destination" maxlength="10" placeholder="Örn. VIE"></label>
                <label>STA<input type="datetime-local" name="scheduled_arrival_at"></label>
                <label>STD<input type="datetime-local" name="scheduled_departure_at"></label>
                <label>ETA<input type="datetime-local" name="estimated_arrival_at"></label>
                <label>ETD<input type="datetime-local" name="estimated_departure_at"></label>
                <label>Kuyruk no<input name="tail_number" maxlength="20" placeholder="Örn. TC-SEI"></label>
                <label>Uçak tipi<input name="aircraft_type" maxlength="20"></label>
                <label>Park<input name="stand" maxlength="20"></label>
                <label class="full">Not<textarea name="note" rows="3"></textarea></label>
                <button class="btn btn-success">Uçuşu Oluştur ve Sorumlu Ata</button>
            </form>
        </section>
    <?php endif;
}

function render_import_review(array $actor): void
{
    $id = (int)($_GET['id'] ?? 0);
    $batch = ImportService::batchForActor($actor, $id);
    if (!$batch) throw new RuntimeException('Import bulunamadı.');
    $rows = ImportService::rows($id);
    $totalRows = count($rows);
    $invalid = ImportService::invalidCount($id);
    $canEdit = $batch['status'] === 'preview' && can($actor, 'imports.stage');
    ?>
    <section class="panel">
        <div class="section-heading"><div><p class="eyebrow">2. adım · <?= e($batch['file_name']) ?></p><h2>Tüm uçuşlar · önizleme ve düzeltme</h2><p class="muted">Exceldeki bütün uçuşlar tek ekrandadır. Bu geçici önizleme aktarılınca veya vazgeçilince silinir.</p></div><div class="actions"><a class="btn btn-ghost" href="<?= e(url_for('/imports')) ?>">Uçuş eklemeye dön</a><?php if ($canEdit): ?><form method="post" data-confirm="Excel önizlemesi tamamen silinecek. Devam edilsin mi?"><input type="hidden" name="action" value="discard_import"><input type="hidden" name="batch_id" value="<?= $id ?>"><?= csrf_field() ?><button class="btn btn-danger">Vazgeç ve Sil</button></form><?php endif; ?></div></div>
        <div class="summary-row"><span><?= $totalRows ?> uçuş</span><span class="text-danger"><?= $invalid ?> hatalı</span><span>Tek ekran</span><span>Durum: <?= e($batch['status']) ?></span></div>
    </section>
    <?php if ($canEdit): ?>
        <form method="post" id="bulk-delete-import-rows" class="panel review-toolbar" data-confirm="Seçili uçuşlar bu önizlemeden silinecek. Devam edilsin mi?">
            <input type="hidden" name="action" value="delete_import_rows"><input type="hidden" name="batch_id" value="<?= $id ?>"><?= csrf_field() ?>
            <label class="check-label"><input type="checkbox" data-review-select-all><span>Tüm uçuşları seç</span></label>
            <span class="muted" data-review-selection-count>0 uçuş seçili</span>
            <button class="btn btn-danger btn-small" data-review-bulk-delete disabled>Seçilenleri Sil</button>
        </form>
    <?php elseif ($batch['status'] === 'preview'): ?>
        <section class="panel notice">Önizleme salt okunur; düzeltme ve silme yetkiniz yok.</section>
    <?php endif; ?>

    <section class="panel table-wrap import-table review-table"><table><thead><tr><th class="review-select-col"></th><th># / Durum</th><th>A/C</th><th>GELİŞ</th><th>GİDİŞ</th><th>PP</th><th>EA</th><th>EAF</th><th>G2</th><th>G1</th><th>STA</th><th>STD</th><th>G1</th><th>G2</th><th>EDF</th><th>TIP</th><th>REG-A</th><th>REG</th><th>REG-D</th><th>Kontrol</th><th>İşlem</th></tr></thead><tbody>
    <?php foreach ($rows as $row):
        $rowId = (int)$row['id'];
        $data = $row['data'];
        $errors = json_decode((string)$row['errors'], true) ?: [];
        $prefix = 'rows[' . $rowId . ']';
        $formId = 'review-row-' . $rowId;
        $deleteFormId = 'delete-review-row-' . $rowId;
        $formAttribute = $canEdit ? ' form="' . e($formId) . '"' : ' disabled';
        ?>
        <tr class="row-<?= e($row['status']) ?>" data-review-row>
            <td class="review-select-col" data-label="Seç"><?php if ($canEdit): ?><input type="checkbox" form="bulk-delete-import-rows" name="row_ids[]" value="<?= $rowId ?>" data-review-select><?php endif; ?></td>
            <td data-label="Satır"><strong>#<?= (int)$row['source_row_number'] ?></strong><span class="badge <?= e($row['status']) ?>"><?= e($row['status']) ?></span><small><?= e($data['flight_type'] ?? '-') ?></small></td>
            <td data-label="A/C"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[airline_icao]" value="<?= e($data['airline_icao'] ?? '') ?>" maxlength="3"></td>
            <td data-label="Geliş"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[arrival_flight_number]" value="<?= e($data['arrival_flight_number'] ?? '') ?>"></td>
            <td data-label="Gidiş"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[departure_flight_number]" value="<?= e($data['departure_flight_number'] ?? '') ?>"></td>
            <td data-label="PP"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[stand]" value="<?= e($data['stand'] ?? '') ?>"></td>
            <td data-label="EA"><input<?= $formAttribute ?> class="review-datetime" type="datetime-local" name="<?= e($prefix) ?>[estimated_arrival_at]" value="<?= e(datetime_local($data['estimated_arrival_at'] ?? null)) ?>"></td>
            <td data-label="EAF"><input<?= $formAttribute ?> class="review-datetime" type="datetime-local" name="<?= e($prefix) ?>[excel_eaf_at]" value="<?= e(datetime_local($data['excel_eaf_at'] ?? null)) ?>"></td>
            <td data-label="G2 Arrival"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[arrival_g2]" value="<?= e($data['arrival_g2'] ?? '') ?>"></td>
            <td data-label="G1 Arrival"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[arrival_origin]" value="<?= e($data['arrival_origin'] ?? '') ?>"></td>
            <td data-label="STA"><input<?= $formAttribute ?> class="review-datetime" type="datetime-local" name="<?= e($prefix) ?>[scheduled_arrival_at]" value="<?= e(datetime_local($data['scheduled_arrival_at'] ?? null)) ?>"></td>
            <td data-label="STD"><input<?= $formAttribute ?> class="review-datetime" type="datetime-local" name="<?= e($prefix) ?>[scheduled_departure_at]" value="<?= e(datetime_local($data['scheduled_departure_at'] ?? null)) ?>"></td>
            <td data-label="G1 Departure"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[departure_destination]" value="<?= e($data['departure_destination'] ?? '') ?>"></td>
            <td data-label="G2 Departure"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[departure_g2]" value="<?= e($data['departure_g2'] ?? '') ?>"></td>
            <td data-label="EDF"><input<?= $formAttribute ?> class="review-datetime" type="datetime-local" name="<?= e($prefix) ?>[estimated_departure_at]" value="<?= e(datetime_local($data['estimated_departure_at'] ?? null)) ?>"></td>
            <td data-label="TIP"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[aircraft_type]" value="<?= e($data['aircraft_type'] ?? '') ?>"></td>
            <td data-label="REG-A"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[registration_arrival]" value="<?= e($data['registration_arrival'] ?? '') ?>"></td>
            <td data-label="REG"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[registration]" value="<?= e($data['registration'] ?? '') ?>"></td>
            <td data-label="REG-D"><input<?= $formAttribute ?> name="<?= e($prefix) ?>[registration_departure]" value="<?= e($data['registration_departure'] ?? '') ?>"></td>
            <td class="review-message" data-label="Kontrol"><?php if ($errors): ?><small class="error-text"><?= e(implode(' ', $errors)) ?></small><?php else: ?><small>Aktarıma hazır</small><?php endif; ?></td>
            <td class="review-actions" data-label="İşlem">
                <?php if ($canEdit): ?>
                    <form method="post" id="<?= e($formId) ?>"><input type="hidden" name="action" value="update_import_rows"><input type="hidden" name="batch_id" value="<?= $id ?>"><?= csrf_field() ?><button class="btn btn-primary btn-small">Kaydet</button></form>
                    <form method="post" id="<?= e($deleteFormId) ?>" data-confirm="Bu uçuş önizlemeden silinecek. Devam edilsin mi?"><input type="hidden" name="action" value="delete_import_rows"><input type="hidden" name="batch_id" value="<?= $id ?>"><input type="hidden" name="row_ids[]" value="<?= $rowId ?>"><?= csrf_field() ?><button class="btn btn-danger btn-small">Sil</button></form>
                    <details class="review-more"><summary>Diğer</summary><div>
                        <label>Arrival destination<input form="<?= e($formId) ?>" name="<?= e($prefix) ?>[arrival_destination]" value="<?= e($data['arrival_destination'] ?? '') ?>"></label>
                        <label>Departure origin<input form="<?= e($formId) ?>" name="<?= e($prefix) ?>[departure_origin]" value="<?= e($data['departure_origin'] ?? '') ?>"></label>
                        <label class="full">Not<textarea form="<?= e($formId) ?>" name="<?= e($prefix) ?>[note]" rows="2"><?= e($data['note'] ?? '') ?></textarea></label>
                    </div></details>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="21" class="empty">Önizlemede uçuş kalmadı.</td></tr><?php endif; ?>
    </tbody></table></section>
    <?php if ($batch['status'] === 'preview' && can($actor, 'imports.commit')): ?>
        <form method="post" class="commit-bar" data-confirm="Önizlemede kalan uçuşlar SQL uçuş tablosuna aktarılacak. Devam edilsin mi?"><input type="hidden" name="action" value="commit_import"><input type="hidden" name="batch_id" value="<?= $id ?>"><?= csrf_field() ?><div><strong>3. adım</strong><p>Hata kalmadığında yalnızca önizlemede bıraktığınız uçuşları aktarın.</p></div><button class="btn btn-success" <?= $invalid || !$totalRows ? 'disabled' : '' ?>>SQL'e Aktar</button></form>
    <?php endif;
}

function render_flight_detail(array $actor): void
{
    $id = (int)($_GET['id'] ?? 0);
    $flight = FlightService::find($id);
    if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
    $context = FlightService::context($flight);
    Authorization::require($actor, 'flights.view', $context);
    $isResponsible = FlightService::isAssignedTo($id, (int)$actor['id']);
    $taskBackUrl = url_for('/') . ($flight['status'] === 'completed' ? '?tab=completed' : '');
    ?>
    <section class="welcome-card">
        <div><p class="eyebrow"><?= e($flight['icao_code'] . ' · ' . $flight['flight_type_name']) ?></p><h2><?= e(($flight['arrival_flight_number'] ?: '-') . ' / ' . ($flight['departure_flight_number'] ?: '-')) ?></h2><p><?= e(($flight['tail_number'] ?: 'Kuyruk yok') . ' · Park ' . ($flight['stand'] ?: '-')) ?> · <strong><?= e(flight_status_label($flight['status'])) ?></strong></p></div>
        <div class="actions"><?php if (is_standard_operation_user($actor)): ?><a class="btn btn-light" href="<?= e($taskBackUrl) ?>">← Görevlere Dön</a><?php endif; ?><?php if (can($actor, 'flights.update', $context)): ?><a class="btn btn-light" href="<?= e(url_for('/flights/edit') . '?id=' . $id) ?>">Bilgileri Düzenle</a><?php endif; ?><?php if (can($actor, 'flights.assign', $context)): ?><a class="btn btn-light" href="<?= e(url_for('/flights/assign') . '?id=' . $id) ?>">Atama</a><?php endif; ?></div>
    </section>
    <section class="flight-leg-grid">
        <article class="panel flight-leg"><p class="eyebrow">Arrival</p><h2><?= e($flight['arrival_flight_number'] ?: '-') ?></h2><p><?= e(($flight['arrival_origin'] ?: '-') . ' → ' . ($flight['arrival_destination'] ?: '-')) ?></p><small>STA <?= e($flight['scheduled_arrival_at'] ? date('d.m.Y H:i', strtotime($flight['scheduled_arrival_at'])) : '-') ?></small></article>
        <article class="panel flight-leg"><p class="eyebrow">Departure / devam seferi</p><h2><?= e($flight['departure_flight_number'] ?: '-') ?></h2><p><?= e(($flight['departure_origin'] ?: '-') . ' → ' . ($flight['departure_destination'] ?: '-')) ?></p><small>STD <?= e($flight['scheduled_departure_at'] ? date('d.m.Y H:i', strtotime($flight['scheduled_departure_at'])) : '-') ?></small></article>
    </section>
    <?php if ($flight['status'] === 'scheduled' && $isResponsible && can($actor, 'flights.complete', $context)): ?>
        <form method="post" action="<?= e(url_for('/flight') . '?id=' . $id) ?>" class="commit-bar operation-start" data-confirm="Bu uçuş operasyonu başlatılacak. Devam edilsin mi?"><input type="hidden" name="action" value="start_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><div><strong>Operasyonu başlat</strong><p>Başlattığınızda görev “Devam ediyor” durumuna geçer.</p></div><button class="btn btn-success">Operasyonu Başlat</button></form>
    <?php elseif ($flight['status'] === 'scheduled' && !$isResponsible): ?>
        <section class="panel notice">Operasyonun başlayabilmesi için uçuşun sorumlu kullanıcıya atanması gerekir.</section>
    <?php endif; ?>
    <?php if (can($actor, 'processes.view', $context)):
        $processes = DB::fetchAll(
            'SELECT pt.id, pt.name, pt.input_type, COALESCE(fp.state, "not_started") AS state,
                    fp.started_at, fp.finished_at, fp.value_datetime, fp.value_text
             FROM flight_type_process_map m JOIN process_types pt ON pt.id = m.process_type_id
             LEFT JOIN flight_processes fp ON fp.flight_id = ? AND fp.process_type_id = pt.id
             WHERE m.flight_type_id = ? ORDER BY m.order_no',
            [$id, (int)$flight['flight_type_id']]
        ); ?>
        <section class="process-grid">
        <?php foreach ($processes as $process):
            $recorded = $process['state'] === 'finished' || $process['value_datetime'] !== null || trim((string)$process['value_text']) !== '';
            $canUpdate = $flight['status'] === 'active' && $isResponsible && can($actor, 'processes.update', $context);
            $canUndo = $canUpdate && ($recorded || $process['state'] !== 'not_started');
            $canEnterValue = $canUpdate && !$recorded && $process['state'] === 'not_started';
            ?>
            <article class="panel process-card state-<?= e($process['state']) ?>" data-process-id="<?= (int)$process['id'] ?>">
                <div class="section-heading"><div><p class="eyebrow">Operasyon süreci</p><h2><?= e($process['name']) ?></h2></div><span class="badge <?= e($process['state']) ?>"><?= e(process_state_label($process['state'])) ?></span></div>
                <?php if ($process['input_type'] === 'datetime' && $process['value_datetime']): ?><p class="process-value"><?= e(date('d.m.Y H:i:s', strtotime($process['value_datetime']))) ?></p><?php endif; ?>
                <?php if ($process['input_type'] === 'text' && trim((string)$process['value_text']) !== ''): ?><p class="process-value"><?= nl2br(e($process['value_text'])) ?></p><?php endif; ?>
                <?php if ($process['input_type'] === 'state' && $process['started_at']): ?><p class="muted">Başlangıç: <?= e(date('d.m.Y H:i:s', strtotime($process['started_at']))) ?><?php if ($process['finished_at']): ?><br>Bitiş: <?= e(date('d.m.Y H:i:s', strtotime($process['finished_at']))) ?><?php endif; ?></p><?php endif; ?>
                <?php if ($canUpdate && $process['input_type'] === 'state'): ?>
                    <div class="process-actions">
                        <?php if ($process['state'] === 'not_started'): ?>
                            <?php render_process_action_form($id, (int)$process['id'], 'start', 'Başlat', 'btn btn-primary btn-small'); ?>
                            <button type="button" class="btn btn-success btn-small" disabled>Bitir</button>
                            <?php render_process_action_form($id, (int)$process['id'], 'not_used', 'Kullanılmadı', 'btn btn-ghost btn-small'); ?>
                        <?php elseif ($process['state'] === 'started'): ?>
                            <button type="button" class="btn btn-warning btn-small" disabled>Devam Ediyor</button>
                            <?php render_process_action_form($id, (int)$process['id'], 'finish', 'Bitir', 'btn btn-success btn-small'); ?>
                            <?php render_process_action_form($id, (int)$process['id'], 'undo', 'Geri Al', 'btn btn-ghost btn-small'); ?>
                        <?php elseif ($process['state'] === 'finished'): ?>
                            <button type="button" class="btn btn-success btn-small" disabled>Tamamlandı</button>
                            <?php render_process_action_form($id, (int)$process['id'], 'undo', 'Geri Al', 'btn btn-warning btn-small'); ?>
                        <?php else: ?>
                            <button type="button" class="btn btn-ghost btn-small" disabled>Kullanılmadı</button>
                            <?php render_process_action_form($id, (int)$process['id'], 'undo', 'Geri Al', 'btn btn-warning btn-small'); ?>
                        <?php endif; ?>
                    </div>
                <?php elseif ($canEnterValue && $process['input_type'] === 'datetime'): ?>
                    <form method="post" action="<?= e(url_for('/flight') . '?id=' . $id) ?>" class="inline-form" data-process-form><input type="hidden" name="action" value="save_process"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="process_type_id" value="<?= (int)$process['id'] ?>"><input type="hidden" name="process_action" value="mark_time"><?= csrf_field() ?><input type="datetime-local" name="value_datetime" value="<?= e(datetime_local($process['value_datetime'])) ?>"><button class="btn btn-small">Saati Kaydet</button></form>
                <?php elseif ($canEnterValue && $process['input_type'] === 'text'): ?>
                    <form method="post" action="<?= e(url_for('/flight') . '?id=' . $id) ?>" data-process-form><input type="hidden" name="action" value="save_process"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="process_type_id" value="<?= (int)$process['id'] ?>"><input type="hidden" name="process_action" value="save_text"><?= csrf_field() ?><textarea name="value_text" rows="3"><?= e($process['value_text']) ?></textarea><button class="btn btn-small">Notu Kaydet</button></form>
                <?php endif; ?>
                <?php if ($canUndo && $process['input_type'] !== 'state'): ?><div class="process-actions"><?php render_process_action_form($id, (int)$process['id'], 'undo', 'Geri Al', 'btn btn-warning btn-small'); ?></div><?php endif; ?>
                <?php if (!$canUpdate && $flight['status'] === 'scheduled' && $isResponsible): ?><p class="process-lock">Uçuşu başlattığınızda bu süreç aktif olacak.</p><?php endif; ?>
            </article>
        <?php endforeach; ?>
        </section>
    <?php else: ?>
        <section class="panel notice">Bu uçuşun operasyon süreçlerini görüntüleme yetkiniz yok.</section>
    <?php endif; ?>
    <?php if ($flight['status'] === 'active' && $isResponsible && can($actor, 'flights.complete', $context)): ?>
        <form method="post" action="<?= e(url_for('/flight') . '?id=' . $id) ?>" class="commit-bar" data-confirm="Uçuş operasyonu tamamlanacak. Devam edilsin mi?"><input type="hidden" name="action" value="complete_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><div><strong>Operasyonu tamamla</strong><p>Süreçlerin durumundan bağımsız olarak operasyonu kapatır.</p></div><button class="btn btn-success">Uçuşu Tamamla</button></form>
    <?php endif;
}

function render_process_action_form(int $flightId, int $processId, string $processAction, string $label, string $buttonClass): void
{
    ?><form method="post" action="<?= e(url_for('/flight') . '?id=' . $flightId) ?>" data-process-form><input type="hidden" name="action" value="save_process"><input type="hidden" name="flight_id" value="<?= $flightId ?>"><input type="hidden" name="process_type_id" value="<?= $processId ?>"><input type="hidden" name="process_action" value="<?= e($processAction) ?>"><?= csrf_field() ?><button class="<?= e($buttonClass) ?>"><?= e($label) ?></button></form><?php
}

function render_reports(array $actor): void
{
    $flights = FlightService::allVisible($actor, [], 'reports.view');
    $byStatus = [];
    $byAirline = [];
    foreach ($flights as $flight) {
        $byStatus[$flight['status']] = ($byStatus[$flight['status']] ?? 0) + 1;
        $icao = (string)$flight['icao_code'];
        if (!isset($byAirline[$icao])) $byAirline[$icao] = ['name' => $flight['airline_name'], 'total' => 0, 'completed' => 0, 'active' => 0];
        $byAirline[$icao]['total']++;
        if ($flight['status'] === 'completed') $byAirline[$icao]['completed']++;
        if ($flight['status'] === 'active') $byAirline[$icao]['active']++;
    }
    ksort($byAirline);
    ?>
    <section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= e(scope_summary($actor)) ?></p><h2>Yetki kapsamındaki operasyon raporu</h2><p class="muted">Rakamlar yalnızca global, atanmış uçuş veya seçili ICAO kapsamınıza giren kayıtları içerir.</p></div></div></section>
    <section class="grid cards-4">
        <div class="metric"><span>Toplam uçuş</span><strong><?= count($flights) ?></strong></div>
        <div class="metric"><span>Planlanan</span><strong><?= (int)($byStatus['scheduled'] ?? 0) ?></strong></div>
        <div class="metric"><span>Aktif</span><strong><?= (int)($byStatus['active'] ?? 0) ?></strong></div>
        <div class="metric accent"><span>Tamamlanan</span><strong><?= (int)($byStatus['completed'] ?? 0) ?></strong></div>
    </section>
    <section class="panel table-wrap"><table><thead><tr><th>ICAO</th><th>Havayolu</th><th>Toplam</th><th>Aktif</th><th>Tamamlanan</th><th>Tamamlama oranı</th></tr></thead><tbody>
    <?php foreach ($byAirline as $icao => $row): $rate = $row['total'] > 0 ? round($row['completed'] * 100 / $row['total']) : 0; ?><tr><td><strong><?= e($icao) ?></strong></td><td><?= e($row['name']) ?></td><td><?= (int)$row['total'] ?></td><td><?= (int)$row['active'] ?></td><td><?= (int)$row['completed'] ?></td><td>%<?= (int)$rate ?></td></tr><?php endforeach; ?>
    <?php if (!$byAirline): ?><tr><td colspan="6" class="empty">Raporlanacak uçuş bulunmuyor.</td></tr><?php endif; ?>
    </tbody></table></section>
    <?php
}

function render_audit(): void
{
    $rows=DB::fetchAll('SELECT al.*,CONCAT(u.first_name," ",u.last_name) AS actor_name FROM audit_logs al LEFT JOIN users u ON u.id=al.actor_user_id ORDER BY al.id DESC LIMIT 300');?><section class="panel table-wrap"><table><thead><tr><th>Tarih</th><th>Kullanıcı</th><th>İşlem</th><th>Kayıt</th><th>Detay</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><?= e(date('d.m.Y H:i:s',strtotime($row['created_at']))) ?></td><td><?= e($row['actor_name']?:'Sistem') ?></td><td><code><?= e($row['action']) ?></code></td><td><?= e($row['entity_type'].' #'.($row['entity_id']?:'-')) ?></td><td><small><?= e($row['new_values']?:'-') ?></small></td></tr><?php endforeach;?></tbody></table></section><?php
}

function render_profile(array $user): void
{
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= e(role_summary($user)) ?></p><h2><?= e($user['name']) ?></h2><p class="muted"><?= e(scope_summary($user)) ?></p></div></div></section><section class="panel narrow"><h2>Şifre değiştir</h2><?php if((int)$user['must_change_password']===1):?><div class="notice">İlk girişte geçici şifrenizi değiştirmeniz gerekiyor.</div><?php endif;?><form method="post" class="form-grid single"><input type="hidden" name="action" value="change_password"><?= csrf_field() ?><label>Mevcut şifre<input type="password" name="current_password" required></label><label>Yeni şifre<input type="password" name="new_password" minlength="12" required></label><label>Yeni şifre tekrar<input type="password" name="new_password_confirmation" minlength="12" required></label><button class="btn btn-primary">Şifreyi Güncelle</button></form></section><?php
}

function render_flash(?array $flash): void { if($flash):?><div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; }
function render_error(Throwable $error): void { render_auth_start('Hata');?><main class="auth-card"><h1>İşlem tamamlanamadı</h1><p><?= e(friendly_error($error)) ?></p><a class="btn btn-primary" href="<?= e(url_for('/')) ?>">Ana sayfaya dön</a></main></body></html><?php }
function flight_status_label(string $status): string
{
    return [
        'scheduled' => 'Planlandı',
        'active' => 'Devam ediyor',
        'completed' => 'Tamamlandı',
        'cancelled' => 'İptal',
        'archived' => 'Arşiv',
    ][$status] ?? $status;
}
function process_state_label(string $state): string
{
    return [
        'not_started' => 'Başlamadı',
        'started' => 'Devam ediyor',
        'finished' => 'Tamamlandı',
        'not_used' => 'Kullanılmadı',
    ][$state] ?? $state;
}
function selected(mixed $value,mixed $expected):string{return (string)$value===(string)$expected?'selected':'';}
function checked(bool $value):string{return $value?'checked':'';}
function options_rows(array $rows,int $selectedId,string $labelKey,string $secondKey=''):void{echo '<option value="">Seçin</option>';foreach($rows as $row){$label=(string)$row[$labelKey].($secondKey!==''?' · '.(string)$row[$secondKey]:'');echo '<option value="'.(int)$row['id'].'" '.selected($selectedId,$row['id']).'>'.e($label).'</option>';}}
function user_role_codes(array $user):array{static $cache=[];$id=(int)$user['id'];if(!isset($cache[$id]))$cache[$id]=array_column(DB::fetchAll('SELECT DISTINCT r.code FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id WHERE urs.user_id=? ORDER BY r.code',[$id]),'code');return $cache[$id];}
function is_standard_operation_user(array $user):bool{$roles=user_role_codes($user);return in_array('operation',$roles,true)&&!in_array('admin',$roles,true)&&!in_array('supervisor',$roles,true);}
function role_summary(array $user):string{$codes=DB::fetchAll('SELECT DISTINCT r.name FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id WHERE urs.user_id=? ORDER BY r.name',[(int)$user['id']]);return implode(' · ',array_column($codes,'name'))?:'Rol yok';}
function scope_summary(array $user):string{if(Authorization::isGlobal($user,'dashboard.view'))return 'Global erişim';$codes=DB::fetchAll('SELECT DISTINCT a.icao_code FROM user_role_scopes urs JOIN airlines a ON a.id=urs.airline_id WHERE urs.user_id=? AND urs.scope_type="airline" ORDER BY a.icao_code',[(int)$user['id']]);$icao=implode(', ',array_column($codes,'icao_code'));return $icao!==''?'ICAO: '.$icao:'Atanmış uçuşlar';}

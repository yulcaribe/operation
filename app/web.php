<?php
declare(strict_types=1);

$operationOutputBufferStarted = false;
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
    if (is_post()) handle_action($user, $path);
    render_page($user, $path, $flash);
} catch (Throwable $error) {
    if ($operationOutputBufferStarted && ob_get_length() !== false) ob_clean();
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
            '/flights/edit' => ['save_flight', 'delete_flight'],
            '/flights/assign' => ['assign_flight'],
            '/flight' => ['start_flight', 'save_process', 'complete_flight'],
            '/imports' => ['stage_import'],
            '/imports/review' => ['update_import_rows', 'delete_import_rows', 'discard_import', 'commit_import'],
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
                $redirect = '/flights';
                break;
            case 'stage_import':
                $batchId = ImportService::stage($user, (array)($_FILES['excel_file'] ?? []));
                $redirect = '/imports/review?id=' . $batchId;
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
    $title = page_title($path);
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> · Operation</title><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>"></head><body>
    <div class="app-shell"><aside class="sidebar" id="sidebar"><a href="<?= e(url_for('/')) ?>" class="brand"><span class="brand-mark">OP</span><span><strong>Operation</strong><small><?= e(role_summary($user)) ?></small></span></a><nav class="nav"><?= nav_links($user, $path) ?></nav></aside>
    <div class="main-shell"><header class="topbar"><button type="button" class="menu-button" data-menu aria-controls="sidebar" aria-expanded="false">☰</button><div><p class="eyebrow"><?= e(scope_summary($user)) ?></p><h1><?= e($title) ?></h1></div><div class="user-box"><span><strong><?= e($user['name']) ?></strong><small><?= e($user['email'] ?: $user['username']) ?></small></span><form method="post" action="<?= e(url_for('/logout')) ?>"><?= csrf_field() ?><button class="btn btn-ghost">Çıkış</button></form></div></header>
    <?php render_flash($flash); ?><main class="content"><?php render_route($user, $path); ?></main></div></div><script src="<?= e(asset_url('assets/js/app.js')) ?>"></script></body></html><?php
}

function page_title(string $path): string
{
    return [
        '/' => 'Genel Bakış', '/users' => 'Kullanıcılar', '/users/edit' => 'Kullanıcı Düzenle', '/roles' => 'Rol ve Yetkiler',
        '/airlines' => 'Havayolları ve ICAO', '/flights' => 'Uçuşlar', '/flights/edit' => 'Uçuş Bilgileri', '/flights/assign' => 'Uçuş Atama',
        '/imports' => 'Excel Import', '/imports/review' => 'Import Önizleme ve Düzeltme', '/flight' => 'Uçuş Operasyonu', '/reports' => 'Raporlar', '/audit' => 'İşlem Kayıtları', '/profile' => 'Profilim',
    ][$path] ?? 'Operation';
}

function nav_links(array $user, string $path): string
{
    $links = ['/'=>'Genel Bakış'];
    if (can($user, 'flights.view')) $links['/flights'] = 'Uçuşlar';
    if (Authorization::canAny($user, ['imports.view', 'imports.stage', 'imports.commit'])) $links['/imports'] = 'Excel Import';
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
        case '/': Authorization::require($user, 'dashboard.view'); render_dashboard($user); break;
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
    ?><section class="welcome-card"><div><p class="eyebrow">Hoş geldiniz</p><h2><?= e($user['name']) ?></h2><p>Menü ve uçuş görünürlüğü rolünüz, özel yetkileriniz ve ICAO kapsamınıza göre oluşturuldu.</p></div><?php if (can($user, 'flights.create')): ?><a class="btn btn-light" href="<?= e(url_for('/flights/edit')) ?>">Yeni Uçuş</a><?php endif; ?></section>
    <section class="grid cards-4"><div class="metric"><span>Devam eden operasyon</span><strong><?= count($flights) ?></strong></div><div class="metric"><span>Sorumlusu atanmış</span><strong><?= $assigned ?></strong></div><div class="metric"><span>Aktif ICAO</span><strong><?= count($airlines) ?></strong></div><div class="metric accent"><span>Kapsam</span><strong><?= e(scope_summary($user)) ?></strong></div></section>
    <section class="panel"><div class="section-heading"><div><p class="eyebrow">Canlı operasyon</p><h2>Devam eden uçuşlar</h2><p class="muted">Bu ekranda yalnızca sorumlusu tarafından başlatılmış operasyonlar görünür.</p></div></div></section>
    <?php render_flight_table($flights, $user); ?>
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
    $permissions = DB::fetchAll('SELECT id, code, name FROM permissions WHERE code IN ("flights.view", "flights.create", "flights.update", "flights.cancel", "flights.delete", "flights.assign", "flights.complete", "processes.view", "processes.update", "processes.override", "reports.view") ORDER BY code');
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
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= count($flights) ?> uçuş</p><h2>Yetki kapsamındaki uçuşlar</h2></div><div class="actions"><?php if (can($actor, 'flights.create')): ?><a class="btn btn-primary" href="<?= e(url_for('/flights/edit')) ?>">Uçuş Ekle</a><?php endif; ?></div></div><nav class="tabs"><?php foreach ([''=>'Tümü','scheduled'=>'Planlanan','active'=>'Devam Eden','completed'=>'Tamamlanan','cancelled'=>'İptal'] as $value=>$label): ?><a class="<?= $status===$value?'active':'' ?>" href="<?= e(url_for('/flights') . ($value ? '?status='.$value : '')) ?>"><?= e($label) ?></a><?php endforeach; ?></nav></section><?php render_flight_table($flights, $actor);
}

function render_flight_table(array $flights, array $actor): void
{
    ?><section class="panel table-wrap"><table class="flight-table"><thead><tr><th>ICAO</th><th>Arrival sefer</th><th>G1 Arrival</th><th>Departure sefer</th><th>G1 Departure</th><th>STA / STD</th><th>Tip</th><th>Uçak / Park</th><th>Sorumlu</th><th>Durum</th><th></th></tr></thead><tbody><?php foreach ($flights as $flight): $context=FlightService::context($flight); ?><tr><td><strong><?= e($flight['icao_code']) ?></strong><small><?= e($flight['airline_name']) ?></small></td><td><strong><?= e($flight['arrival_flight_number'] ?: '-') ?></strong></td><td><strong><?= e($flight['arrival_origin'] ?: '-') ?></strong></td><td><strong><?= e($flight['departure_flight_number'] ?: '-') ?></strong></td><td><strong><?= e($flight['departure_destination'] ?: '-') ?></strong></td><td><strong>STA <?= e($flight['scheduled_arrival_at'] ? date('d.m H:i', strtotime($flight['scheduled_arrival_at'])) : '-') ?></strong><small>STD <?= e($flight['scheduled_departure_at'] ? date('d.m H:i', strtotime($flight['scheduled_departure_at'])) : '-') ?></small></td><td><?= e($flight['flight_type_name']) ?></td><td><?= e($flight['tail_number'] ?: '-') ?><small>Park <?= e($flight['stand'] ?: '-') ?></small></td><td><?= e($flight['assignee_name'] ?: 'Atanmamış') ?></td><td><span class="badge <?= e($flight['status']) ?>"><?= e(flight_status_label($flight['status'])) ?></span></td><td class="actions"><a class="btn btn-small" href="<?= e(url_for('/flight').'?id='.(int)$flight['id']) ?>">Aç</a><?php if (can($actor,'flights.update',$context)): ?><a class="btn btn-small" href="<?= e(url_for('/flights/edit').'?id='.(int)$flight['id']) ?>">Düzenle</a><?php endif; ?><?php if (can($actor,'flights.assign',$context)): ?><a class="btn btn-small" href="<?= e(url_for('/flights/assign').'?id='.(int)$flight['id']) ?>">Ata</a><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$flights):?><tr><td colspan="11" class="empty">Uçuş bulunmuyor.</td></tr><?php endif;?></tbody></table></section><?php
}

function render_flight_edit(array $actor): void
{
    $id=(int)($_GET['id']??0); $flight=$id?FlightService::find($id):null;
    if($id&&!$flight) throw new RuntimeException('Uçuş bulunamadı.');
    Authorization::require($actor,$flight?'flights.update':'flights.create',$flight?FlightService::context($flight):[]);
    $permission = $flight ? 'flights.update' : 'flights.create';
    $airlines = array_values(array_filter(
        DB::fetchAll('SELECT id, icao_code, name FROM airlines WHERE status="active" ORDER BY icao_code'),
        static function (array $airline) use ($actor, $flight, $permission): bool {
            if ($flight && (int)$flight['airline_id'] === (int)$airline['id']) return true;
            return can($actor, $permission, ['airline_id' => (int)$airline['id']]);
        }
    ));
    $types=DB::fetchAll('SELECT id,name FROM flight_types WHERE status="active" ORDER BY id'); $f=$flight?:[];
    $statuses = [(string)($f['status'] ?? 'scheduled')];
    if ($flight && !in_array($flight['status'], ['completed', 'cancelled'], true) && can($actor, 'flights.cancel', FlightService::context($flight))) $statuses[] = 'cancelled';
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= $flight?'Uçuş #'.$id:'Yeni kayıt' ?></p><h2>Uçuş bilgileri</h2></div><a class="btn btn-ghost" href="<?= e(url_for('/flights')) ?>">Listeye dön</a></div><form method="post" class="form-grid"><input type="hidden" name="action" value="save_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><label>Havayolu<select name="airline_id" required><?php options_rows($airlines,(int)($f['airline_id']??0),'icao_code','name'); ?></select></label><label>Uçuş tipi<select name="flight_type_id" required><?php options_rows($types,(int)($f['flight_type_id']??0),'name'); ?></select></label><label>Arrival uçuş no<input name="arrival_flight_number" value="<?= e($f['arrival_flight_number']??'') ?>"></label><label>Departure uçuş no<input name="departure_flight_number" value="<?= e($f['departure_flight_number']??'') ?>"></label><label>Arrival origin<input name="arrival_origin" value="<?= e($f['arrival_origin']??'') ?>"></label><label>Arrival destination<input name="arrival_destination" value="<?= e($f['arrival_destination']??'') ?>"></label><label>Departure origin<input name="departure_origin" value="<?= e($f['departure_origin']??'') ?>"></label><label>Departure destination<input name="departure_destination" value="<?= e($f['departure_destination']??'') ?>"></label><label>STA<input type="datetime-local" name="scheduled_arrival_at" value="<?= e(datetime_local($f['scheduled_arrival_at']??null)) ?>"></label><label>ETA<input type="datetime-local" name="estimated_arrival_at" value="<?= e(datetime_local($f['estimated_arrival_at']??null)) ?>"></label><label>STD<input type="datetime-local" name="scheduled_departure_at" value="<?= e(datetime_local($f['scheduled_departure_at']??null)) ?>"></label><label>ETD<input type="datetime-local" name="estimated_departure_at" value="<?= e(datetime_local($f['estimated_departure_at']??null)) ?>"></label><label>Kuyruk no<input name="tail_number" value="<?= e($f['tail_number']??'') ?>"></label><label>Uçak tipi<input name="aircraft_type" value="<?= e($f['aircraft_type']??'') ?>"></label><label>Park<input name="stand" value="<?= e($f['stand']??'') ?>"></label><label>Durum<select name="status"><?php foreach($statuses as $status):?><option value="<?= e($status) ?>" <?= selected($f['status']??'scheduled',$status) ?>><?= e($status) ?></option><?php endforeach;?></select></label><label class="full">Not<textarea name="note" rows="3"><?= e($f['note']??'') ?></textarea></label><button class="btn btn-primary">Kaydet</button></form></section>
    <?php if ($flight && can($actor, 'flights.delete', FlightService::context($flight))): ?><section class="panel danger-zone"><h2>Uçuşu kalıcı sil</h2><p>Uçuş ve bağlı operasyon kayıtları sistemden silinir; yalnızca kısa işlem kaydı tutulur.</p><form method="post" data-confirm="Uçuş kalıcı olarak silinecek. Bu işlem geri alınamaz. Devam edilsin mi?"><input type="hidden" name="action" value="delete_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><button class="btn btn-danger">Uçuşu Sil</button></form></section><?php endif;
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
    <?php if (in_array($flight['status'], ['completed', 'cancelled'], true)): ?>
    <section class="panel notice">Tamamlanmış veya iptal edilmiş uçuşun sorumlusu değiştirilemez.</section>
    <?php else: ?>
    <section class="panel"><form method="post"><input type="hidden" name="action" value="assign_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><div class="assignment-grid"><label class="assignment-card"><input type="radio" name="user_id" value="0" <?= checked($assignedUserId === 0) ?>><span><strong>Atanmamış</strong><small>Aktif sorumluyu kaldır</small></span></label><?php foreach($users as $item):?><label class="assignment-card"><input type="radio" name="user_id" value="<?= (int)$item['id'] ?>" <?= checked((int)$item['id'] === $assignedUserId) ?>><span><strong><?= e($item['first_name'].' '.$item['last_name']) ?></strong><small><?= e($item['username']) ?></small></span></label><?php endforeach;?></div><button class="btn btn-primary">Sorumluyu Kaydet</button></form></section><?php
    endif;
}

function render_imports(array $actor): void
{
    ImportService::cleanupTransient();
    if(can($actor,'imports.stage')):?><section class="panel"><div class="section-heading"><div><p class="eyebrow">Geçici Excel önizlemesi</p><h2>Excel yükle</h2><p class="muted">İlk sayfanın A:Q kolonları okunur. Önizleme geçmiş olarak tutulmaz; SQL aktarımında veya vazgeçildiğinde tamamen silinir.</p></div></div><form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="action" value="stage_import"><?= csrf_field() ?><label>XLSX veya CSV<input type="file" name="excel_file" accept=".xlsx,.csv" required></label><button class="btn btn-primary">Önizleme Oluştur</button></form><p class="muted">Yeni dosya yüklemek önceki tamamlanmamış önizlemenizi siler. İki saatten eski geçici önizlemeler otomatik temizlenir.</p></section><?php endif;
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
        <div class="section-heading"><div><p class="eyebrow">2. adım · <?= e($batch['file_name']) ?></p><h2>Tüm uçuşlar · önizleme ve düzeltme</h2><p class="muted">Exceldeki bütün uçuşlar tek ekrandadır. Bu geçici önizleme aktarılınca veya vazgeçilince silinir.</p></div><div class="actions"><a class="btn btn-ghost" href="<?= e(url_for('/imports')) ?>">Excel yüklemeye dön</a><?php if ($canEdit): ?><form method="post" data-confirm="Excel önizlemesi tamamen silinecek. Devam edilsin mi?"><input type="hidden" name="action" value="discard_import"><input type="hidden" name="batch_id" value="<?= $id ?>"><?= csrf_field() ?><button class="btn btn-danger">Vazgeç ve Sil</button></form><?php endif; ?></div></div>
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
    ?>
    <section class="welcome-card">
        <div><p class="eyebrow"><?= e($flight['icao_code'] . ' · ' . $flight['flight_type_name']) ?></p><h2><?= e(($flight['arrival_flight_number'] ?: '-') . ' / ' . ($flight['departure_flight_number'] ?: '-')) ?></h2><p><?= e(($flight['tail_number'] ?: 'Kuyruk yok') . ' · Park ' . ($flight['stand'] ?: '-')) ?> · <strong><?= e(flight_status_label($flight['status'])) ?></strong></p></div>
        <div class="actions"><?php if (can($actor, 'flights.update', $context)): ?><a class="btn btn-light" href="<?= e(url_for('/flights/edit') . '?id=' . $id) ?>">Bilgileri Düzenle</a><?php endif; ?><?php if (can($actor, 'flights.assign', $context)): ?><a class="btn btn-light" href="<?= e(url_for('/flights/assign') . '?id=' . $id) ?>">Atama</a><?php endif; ?></div>
    </section>
    <section class="flight-leg-grid">
        <article class="panel flight-leg"><p class="eyebrow">Arrival</p><h2><?= e($flight['arrival_flight_number'] ?: '-') ?></h2><p><?= e(($flight['arrival_origin'] ?: '-') . ' → ' . ($flight['arrival_destination'] ?: '-')) ?></p><small>STA <?= e($flight['scheduled_arrival_at'] ? date('d.m.Y H:i', strtotime($flight['scheduled_arrival_at'])) : '-') ?></small></article>
        <article class="panel flight-leg"><p class="eyebrow">Departure / devam seferi</p><h2><?= e($flight['departure_flight_number'] ?: '-') ?></h2><p><?= e(($flight['departure_origin'] ?: '-') . ' → ' . ($flight['departure_destination'] ?: '-')) ?></p><small>STD <?= e($flight['scheduled_departure_at'] ? date('d.m.Y H:i', strtotime($flight['scheduled_departure_at'])) : '-') ?></small></article>
    </section>
    <?php if ($flight['status'] === 'scheduled' && $isResponsible && can($actor, 'flights.complete', $context)): ?>
        <form method="post" action="<?= e(url_for('/flight') . '?id=' . $id) ?>" class="commit-bar operation-start" data-confirm="Bu uçuş operasyonu başlatılacak. Devam edilsin mi?"><input type="hidden" name="action" value="start_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><div><strong>Operasyonu başlat</strong><p>Başlattığınızda uçuş Genel Bakış ekranında “Devam ediyor” olarak görünür.</p></div><button class="btn btn-success">Operasyonu Başlat</button></form>
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
            $canOverride = can($actor, 'processes.override', $context);
            $flightIsClosed = in_array($flight['status'], ['completed', 'cancelled', 'archived'], true);
            $canEdit = $flight['status'] === 'active' && $isResponsible && can($actor, 'processes.update', $context) && ((!$recorded && !$flightIsClosed) || $canOverride);
            ?>
            <article class="panel process-card state-<?= e($process['state']) ?>" data-process-id="<?= (int)$process['id'] ?>">
                <div class="section-heading"><div><p class="eyebrow">Operasyon süreci</p><h2><?= e($process['name']) ?></h2></div><span class="badge <?= e($process['state']) ?>"><?= e($process['state']) ?></span></div>
                <?php if ($process['input_type'] === 'datetime' && $process['value_datetime']): ?><p class="process-value"><?= e(date('d.m.Y H:i:s', strtotime($process['value_datetime']))) ?></p><?php endif; ?>
                <?php if ($process['input_type'] === 'text' && trim((string)$process['value_text']) !== ''): ?><p class="process-value"><?= nl2br(e($process['value_text'])) ?></p><?php endif; ?>
                <?php if ($process['input_type'] === 'state' && $process['started_at']): ?><p class="muted">Başlangıç: <?= e(date('d.m.Y H:i:s', strtotime($process['started_at']))) ?><?php if ($process['finished_at']): ?><br>Bitiş: <?= e(date('d.m.Y H:i:s', strtotime($process['finished_at']))) ?><?php endif; ?></p><?php endif; ?>
                <?php if ($canEdit && $process['input_type'] === 'state'): ?>
                    <div class="actions"><?php foreach (['start' => 'Başlat', 'finish' => 'Bitir', 'not_used' => 'Kullanılmadı'] as $action => $label): ?><form method="post" action="<?= e(url_for('/flight') . '?id=' . $id) ?>" data-process-form><input type="hidden" name="action" value="save_process"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="process_type_id" value="<?= (int)$process['id'] ?>"><input type="hidden" name="process_action" value="<?= e($action) ?>"><?= csrf_field() ?><button class="btn btn-small"><?= e($label) ?></button></form><?php endforeach; ?></div>
                <?php elseif ($canEdit && $process['input_type'] === 'datetime'): ?>
                    <form method="post" action="<?= e(url_for('/flight') . '?id=' . $id) ?>" class="inline-form" data-process-form><input type="hidden" name="action" value="save_process"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="process_type_id" value="<?= (int)$process['id'] ?>"><input type="hidden" name="process_action" value="mark_time"><?= csrf_field() ?><input type="datetime-local" name="value_datetime" value="<?= e(datetime_local($process['value_datetime'])) ?>"><button class="btn btn-small">Saati Kaydet</button></form>
                <?php elseif ($canEdit && $process['input_type'] === 'text'): ?>
                    <form method="post" action="<?= e(url_for('/flight') . '?id=' . $id) ?>" data-process-form><input type="hidden" name="action" value="save_process"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="process_type_id" value="<?= (int)$process['id'] ?>"><input type="hidden" name="process_action" value="save_text"><?= csrf_field() ?><textarea name="value_text" rows="3"><?= e($process['value_text']) ?></textarea><button class="btn btn-small">Notu Kaydet</button></form>
                <?php endif; ?>
                <?php if ($canOverride && ($recorded || $process['state'] !== 'not_started')): ?><form method="post" action="<?= e(url_for('/flight') . '?id=' . $id) ?>" class="reset-form" data-process-form data-confirm="Bu süreç kaydı sıfırlanacak. Devam edilsin mi?"><input type="hidden" name="action" value="save_process"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="process_type_id" value="<?= (int)$process['id'] ?>"><input type="hidden" name="process_action" value="reset"><?= csrf_field() ?><button class="btn btn-ghost btn-small">Sıfırla</button></form><?php endif; ?>
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
function selected(mixed $value,mixed $expected):string{return (string)$value===(string)$expected?'selected':'';}
function checked(bool $value):string{return $value?'checked':'';}
function options_rows(array $rows,int $selectedId,string $labelKey,string $secondKey=''):void{echo '<option value="">Seçin</option>';foreach($rows as $row){$label=(string)$row[$labelKey].($secondKey!==''?' · '.(string)$row[$secondKey]:'');echo '<option value="'.(int)$row['id'].'" '.selected($selectedId,$row['id']).'>'.e($label).'</option>';}}
function role_summary(array $user):string{$codes=DB::fetchAll('SELECT DISTINCT r.name FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id WHERE urs.user_id=? ORDER BY r.name',[(int)$user['id']]);return implode(' · ',array_column($codes,'name'))?:'Rol yok';}
function scope_summary(array $user):string{if(Authorization::isGlobal($user,'dashboard.view'))return 'Global erişim';$codes=DB::fetchAll('SELECT DISTINCT a.icao_code FROM user_role_scopes urs JOIN airlines a ON a.id=urs.airline_id WHERE urs.user_id=? AND urs.scope_type="airline" ORDER BY a.icao_code',[(int)$user['id']]);$icao=implode(', ',array_column($codes,'icao_code'));return $icao!==''?'ICAO: '.$icao:'Atanmış uçuşlar';}

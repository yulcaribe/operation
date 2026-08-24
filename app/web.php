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
            '/flights/edit' => ['save_flight', 'archive_flight', 'delete_flight'],
            '/flights/assign' => ['assign_flight'],
            '/flight' => ['save_process', 'complete_flight'],
            '/imports' => ['stage_import'],
            '/imports/review' => ['update_import_rows', 'commit_import'],
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
            case 'archive_flight':
                FlightService::archive($user, (int)($_POST['flight_id'] ?? 0));
                $redirect = '/flights';
                break;
            case 'delete_flight':
                FlightService::delete($user, (int)($_POST['flight_id'] ?? 0));
                $redirect = '/flights';
                break;
            case 'assign_flight':
                $flightId = (int)($_POST['flight_id'] ?? 0);
                FlightService::assign($user, $flightId, (array)($_POST['user_ids'] ?? []));
                $redirect = '/flights/assign?id=' . $flightId;
                break;
            case 'save_process':
                ProcessService::save($user, $_POST);
                $redirect = '/flight?id=' . (int)($_POST['flight_id'] ?? 0);
                break;
            case 'complete_flight':
                FlightService::complete($user, (int)($_POST['flight_id'] ?? 0));
                $redirect = '/flights';
                break;
            case 'stage_import':
                $batchId = ImportService::stage($user, (array)($_FILES['excel_file'] ?? []));
                $redirect = '/imports/review?id=' . $batchId;
                break;
            case 'update_import_rows':
                $batchId = (int)($_POST['batch_id'] ?? 0);
                ImportService::updateRows($user, $batchId, (array)($_POST['rows'] ?? []));
                $redirect = '/imports/review?id=' . $batchId . '&page=' . max(1, (int)($_POST['page'] ?? 1));
                break;
            case 'commit_import':
                $batchId = (int)($_POST['batch_id'] ?? 0);
                ImportService::commit($user, $batchId);
                $redirect = '/imports/review?id=' . $batchId;
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
    $scheduled = FlightService::allVisible($user, ['status' => 'scheduled']);
    $airlines = [];
    foreach (array_merge($flights, $scheduled) as $flight) $airlines[$flight['icao_code']] = true;
    ?><section class="welcome-card"><div><p class="eyebrow">Hoş geldiniz</p><h2><?= e($user['name']) ?></h2><p>Menü ve uçuş görünürlüğü rolünüz, özel yetkileriniz ve ICAO kapsamınıza göre oluşturuldu.</p></div><?php if (can($user, 'flights.create')): ?><a class="btn btn-light" href="<?= e(url_for('/flights/edit')) ?>">Yeni Uçuş</a><?php endif; ?></section>
    <section class="grid cards-4"><div class="metric"><span>Aktif uçuş</span><strong><?= count($flights) ?></strong></div><div class="metric"><span>Planlanan</span><strong><?= count($scheduled) ?></strong></div><div class="metric"><span>Görünen ICAO</span><strong><?= count($airlines) ?></strong></div><div class="metric accent"><span>Kapsam</span><strong><?= e(scope_summary($user)) ?></strong></div></section>
    <?php render_flight_table(array_slice(array_merge($flights, $scheduled), 0, 12), $user); ?>
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
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= count($flights) ?> uçuş</p><h2>Yetki kapsamındaki uçuşlar</h2></div><div class="actions"><?php if (can($actor, 'flights.create')): ?><a class="btn btn-primary" href="<?= e(url_for('/flights/edit')) ?>">Uçuş Ekle</a><?php endif; ?></div></div><nav class="tabs"><?php foreach ([''=>'Tümü','scheduled'=>'Planlanan','active'=>'Aktif','completed'=>'Tamamlanan','cancelled'=>'İptal','archived'=>'Arşiv'] as $value=>$label): ?><a class="<?= $status===$value?'active':'' ?>" href="<?= e(url_for('/flights') . ($value ? '?status='.$value : '')) ?>"><?= e($label) ?></a><?php endforeach; ?></nav></section><?php render_flight_table($flights, $actor);
}

function render_flight_table(array $flights, array $actor): void
{
    ?><section class="panel table-wrap"><table><thead><tr><th>ICAO</th><th>Uçuş</th><th>Tip</th><th>Planlanan</th><th>Uçak/Park</th><th>Atananlar</th><th>Durum</th><th></th></tr></thead><tbody><?php foreach ($flights as $flight): $context=FlightService::context($flight); $scheduled=$flight['scheduled_departure_at']?:$flight['scheduled_arrival_at']; ?><tr><td><strong><?= e($flight['icao_code']) ?></strong><small><?= e($flight['airline_name']) ?></small></td><td><?= e($flight['departure_flight_number'] ?: $flight['arrival_flight_number'] ?: '-') ?></td><td><?= e($flight['flight_type_name']) ?></td><td><?= e($scheduled ? date('d.m.Y H:i', strtotime($scheduled)) : '-') ?></td><td><?= e($flight['tail_number'] ?: '-') ?><small><?= e($flight['stand'] ?: '-') ?></small></td><td><?= e($flight['assignees'] ?: 'Atanmamış') ?></td><td><span class="badge <?= e($flight['status']) ?>"><?= e($flight['status']) ?></span></td><td class="actions"><a class="btn btn-small" href="<?= e(url_for('/flight').'?id='.(int)$flight['id']) ?>">Aç</a><?php if (can($actor,'flights.update',$context)): ?><a class="btn btn-small" href="<?= e(url_for('/flights/edit').'?id='.(int)$flight['id']) ?>">Düzenle</a><?php endif; ?><?php if (can($actor,'flights.assign',$context)): ?><a class="btn btn-small" href="<?= e(url_for('/flights/assign').'?id='.(int)$flight['id']) ?>">Ata</a><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$flights):?><tr><td colspan="8" class="empty">Uçuş bulunmuyor.</td></tr><?php endif;?></tbody></table></section><?php
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
    $canChangeClosedStatus = $flight && can($actor, 'flights.cancel', FlightService::context($flight));
    $flightIsClosed = $flight && in_array($flight['status'], ['completed', 'cancelled', 'archived'], true);
    $statuses = $flightIsClosed && !$canChangeClosedStatus ? [$flight['status']] : ['scheduled', 'active'];
    if ($canChangeClosedStatus) $statuses = array_merge($statuses, ['cancelled', 'archived']);
    if ($flight && !in_array($flight['status'], $statuses, true)) $statuses[] = $flight['status'];
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= $flight?'Uçuş #'.$id:'Yeni kayıt' ?></p><h2>Uçuş bilgileri</h2></div><a class="btn btn-ghost" href="<?= e(url_for('/flights')) ?>">Listeye dön</a></div><form method="post" class="form-grid"><input type="hidden" name="action" value="save_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><label>Havayolu<select name="airline_id" required><?php options_rows($airlines,(int)($f['airline_id']??0),'icao_code','name'); ?></select></label><label>Uçuş tipi<select name="flight_type_id" required><?php options_rows($types,(int)($f['flight_type_id']??0),'name'); ?></select></label><label>Arrival uçuş no<input name="arrival_flight_number" value="<?= e($f['arrival_flight_number']??'') ?>"></label><label>Departure uçuş no<input name="departure_flight_number" value="<?= e($f['departure_flight_number']??'') ?>"></label><label>Arrival origin<input name="arrival_origin" value="<?= e($f['arrival_origin']??'') ?>"></label><label>Arrival destination<input name="arrival_destination" value="<?= e($f['arrival_destination']??'') ?>"></label><label>Departure origin<input name="departure_origin" value="<?= e($f['departure_origin']??'') ?>"></label><label>Departure destination<input name="departure_destination" value="<?= e($f['departure_destination']??'') ?>"></label><label>STA<input type="datetime-local" name="scheduled_arrival_at" value="<?= e(datetime_local($f['scheduled_arrival_at']??null)) ?>"></label><label>ETA<input type="datetime-local" name="estimated_arrival_at" value="<?= e(datetime_local($f['estimated_arrival_at']??null)) ?>"></label><label>STD<input type="datetime-local" name="scheduled_departure_at" value="<?= e(datetime_local($f['scheduled_departure_at']??null)) ?>"></label><label>ETD<input type="datetime-local" name="estimated_departure_at" value="<?= e(datetime_local($f['estimated_departure_at']??null)) ?>"></label><label>Kuyruk no<input name="tail_number" value="<?= e($f['tail_number']??'') ?>"></label><label>Uçak tipi<input name="aircraft_type" value="<?= e($f['aircraft_type']??'') ?>"></label><label>Park<input name="stand" value="<?= e($f['stand']??'') ?>"></label><label>Durum<select name="status"><?php foreach($statuses as $status):?><option value="<?= e($status) ?>" <?= selected($f['status']??'scheduled',$status) ?>><?= e($status) ?></option><?php endforeach;?></select></label><label class="full">Not<textarea name="note" rows="3"><?= e($f['note']??'') ?></textarea></label><button class="btn btn-primary">Kaydet</button></form></section>
    <?php if($flight&&can($actor,'flights.cancel',FlightService::context($flight))):?><section class="panel danger-zone"><h2>Uçuşu arşivle</h2><form method="post" data-confirm="Uçuş arşivlenecek. Devam edilsin mi?"><input type="hidden" name="action" value="archive_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><button class="btn btn-danger">Arşivle</button></form></section><?php endif;
    if ($flight && can($actor, 'flights.delete', FlightService::context($flight))): ?><section class="panel danger-zone"><h2>Uçuşu sistemden kaldır</h2><p>Uçuş listelerden kaldırılır; denetim geçmişi korunur.</p><form method="post" data-confirm="Uçuş sistemden kaldırılacak. Bu işlem geri alınamaz. Devam edilsin mi?"><input type="hidden" name="action" value="delete_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><button class="btn btn-danger">Uçuşu Kaldır</button></form></section><?php endif;
}

function render_flight_assign(array $actor): void
{
    $id=(int)($_GET['id']??0);$flight=FlightService::find($id);if(!$flight)throw new RuntimeException('Uçuş bulunamadı.');Authorization::require($actor,'flights.assign',FlightService::context($flight));$users=DB::fetchAll('SELECT id,username,first_name,last_name FROM users WHERE status="active" AND deleted_at IS NULL ORDER BY first_name,last_name');$assigned=array_map('intval',array_column(FlightService::assignments($id),'user_id'));
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= e($flight['icao_code']) ?> · <?= e($flight['departure_flight_number']?:$flight['arrival_flight_number']?:'#'.$id) ?></p><h2>Ayrı uçuş atama ekranı</h2><p class="muted">Admin dahil birden fazla aktif kullanıcı seçilebilir.</p></div><a class="btn btn-ghost" href="<?= e(url_for('/flights')) ?>">Uçuşlara dön</a></div><form method="post"><input type="hidden" name="action" value="assign_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><div class="assignment-grid"><?php foreach($users as $item):?><label class="assignment-card"><input type="checkbox" name="user_ids[]" value="<?= (int)$item['id'] ?>" <?= checked(in_array((int)$item['id'],$assigned,true)) ?>><span><strong><?= e($item['first_name'].' '.$item['last_name']) ?></strong><small><?= e($item['username']) ?></small></span></label><?php endforeach;?></div><button class="btn btn-primary">Atamaları Kaydet</button></form></section><?php
}

function render_imports(array $actor): void
{
    $batches=ImportService::batches();
    if(can($actor,'imports.stage')):?><section class="panel"><div class="section-heading"><div><p class="eyebrow">1. adım</p><h2>Excel yükle</h2><p class="muted">Dosya doğrudan uçuşlara aktarılmaz; düzenlenebilir önizleme oluşturulur.</p></div></div><form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="action" value="stage_import"><?= csrf_field() ?><label>XLSX veya CSV<input type="file" name="excel_file" accept=".xlsx,.csv" required></label><button class="btn btn-primary">Önizleme Oluştur</button></form></section><?php endif;?>
    <section class="panel table-wrap"><table><thead><tr><th>Dosya</th><th>Yükleyen</th><th>Satır</th><th>Geçerli/Aktarılan</th><th>Hatalı/Atlanan</th><th>Durum</th><th></th></tr></thead><tbody><?php foreach($batches as $batch):?><tr><td><?= e($batch['file_name']) ?><small><?= e(date('d.m.Y H:i',strtotime($batch['created_at']))) ?></small></td><td><?= e($batch['imported_by_name']?:'-') ?></td><td><?= (int)$batch['total_rows'] ?></td><td><?= (int)$batch['success_rows'] ?></td><td><?= (int)$batch['failed_rows'] ?></td><td><span class="badge <?= e($batch['status']) ?>"><?= e($batch['status']) ?></span></td><td><a class="btn btn-small" href="<?= e(url_for('/imports/review').'?id='.(int)$batch['id']) ?>">Aç</a></td></tr><?php endforeach;?></tbody></table></section><?php
}

function render_import_review(array $actor): void
{
    $id = (int)($_GET['id'] ?? 0);
    $batch = ImportService::batch($id);
    if (!$batch) throw new RuntimeException('Import bulunamadı.');
    $perPage = 40;
    $totalRows = (int)$batch['total_rows'];
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $rows = ImportService::rowsPage($id, $perPage, ($page - 1) * $perPage);
    $invalid = ImportService::invalidCount($id);
    ?>
    <section class="panel">
        <div class="section-heading"><div><p class="eyebrow">2. adım · <?= e($batch['file_name']) ?></p><h2>Önizleme ve düzeltme</h2><p class="muted">Hatalı hücreleri düzeltip kaydedin. Gerçek uçuş tablosuna yalnızca “SQL'e Aktar” onayından sonra yazılır.</p></div><a class="btn btn-ghost" href="<?= e(url_for('/imports')) ?>">Importlara dön</a></div>
        <div class="summary-row"><span><?= $totalRows ?> satır</span><span class="text-danger"><?= $invalid ?> hatalı</span><span>Sayfa <?= $page ?>/<?= $totalPages ?></span><span>Durum: <?= e($batch['status']) ?></span></div>
    </section>
    <?php if ($batch['status'] === 'preview' && can($actor, 'imports.stage')): ?>
        <form method="post"><input type="hidden" name="action" value="update_import_rows"><input type="hidden" name="batch_id" value="<?= $id ?>"><input type="hidden" name="page" value="<?= $page ?>"><?= csrf_field() ?>
            <section class="panel table-wrap import-table"><table><thead><tr><th># / Durum</th><th>ICAO</th><th>Tip</th><th>ARR no</th><th>DEP no</th><th>STA / ETA</th><th>STD / ETD</th><th>ARR rota</th><th>DEP rota</th><th>Kuyruk</th><th>Uçak</th><th>Park</th><th>Not/Hata</th></tr></thead><tbody>
            <?php foreach ($rows as $row): $data = $row['data']; $errors = json_decode((string)$row['errors'], true) ?: []; $prefix = 'rows[' . (int)$row['id'] . ']'; ?>
                <tr class="row-<?= e($row['status']) ?>">
                    <td><strong><?= (int)$row['row_number'] ?></strong><small><?= e($row['status']) ?></small></td>
                    <td><input name="<?= e($prefix) ?>[airline_icao]" value="<?= e($data['airline_icao'] ?? '') ?>"></td>
                    <td><select name="<?= e($prefix) ?>[flight_type]"><?php foreach (['arrival', 'departure', 'turnaround'] as $type): ?><option value="<?= e($type) ?>" <?= selected($data['flight_type'] ?? '', $type) ?>><?= e($type) ?></option><?php endforeach; ?></select></td>
                    <td><input name="<?= e($prefix) ?>[arrival_flight_number]" value="<?= e($data['arrival_flight_number'] ?? '') ?>"></td>
                    <td><input name="<?= e($prefix) ?>[departure_flight_number]" value="<?= e($data['departure_flight_number'] ?? '') ?>"></td>
                    <td><input type="datetime-local" name="<?= e($prefix) ?>[scheduled_arrival_at]" value="<?= e(datetime_local($data['scheduled_arrival_at'] ?? null)) ?>" title="STA"><input type="datetime-local" name="<?= e($prefix) ?>[estimated_arrival_at]" value="<?= e(datetime_local($data['estimated_arrival_at'] ?? null)) ?>" title="ETA"></td>
                    <td><input type="datetime-local" name="<?= e($prefix) ?>[scheduled_departure_at]" value="<?= e(datetime_local($data['scheduled_departure_at'] ?? null)) ?>" title="STD"><input type="datetime-local" name="<?= e($prefix) ?>[estimated_departure_at]" value="<?= e(datetime_local($data['estimated_departure_at'] ?? null)) ?>" title="ETD"></td>
                    <td><input name="<?= e($prefix) ?>[arrival_origin]" value="<?= e($data['arrival_origin'] ?? '') ?>" placeholder="ORG"><input name="<?= e($prefix) ?>[arrival_destination]" value="<?= e($data['arrival_destination'] ?? '') ?>" placeholder="DST"></td>
                    <td><input name="<?= e($prefix) ?>[departure_origin]" value="<?= e($data['departure_origin'] ?? '') ?>" placeholder="ORG"><input name="<?= e($prefix) ?>[departure_destination]" value="<?= e($data['departure_destination'] ?? '') ?>" placeholder="DST"></td>
                    <td><input name="<?= e($prefix) ?>[tail_number]" value="<?= e($data['tail_number'] ?? '') ?>"></td>
                    <td><input name="<?= e($prefix) ?>[aircraft_type]" value="<?= e($data['aircraft_type'] ?? '') ?>"></td>
                    <td><input name="<?= e($prefix) ?>[stand]" value="<?= e($data['stand'] ?? '') ?>"></td>
                    <td><textarea name="<?= e($prefix) ?>[note]" rows="2"><?= e($data['note'] ?? '') ?></textarea><?php if ($errors): ?><small class="error-text"><?= e(implode(' ', $errors)) ?></small><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></section><?php render_pagination('/imports/review', ['id' => $id], $page, $totalPages); ?><button class="btn btn-primary">Bu Sayfadaki Düzeltmeleri Kaydet ve Tüm Dosyayı Kontrol Et</button>
        </form>
    <?php elseif ($batch['status'] === 'preview'): ?>
        <section class="panel notice">Önizleme salt okunur; düzeltme yetkiniz yok.</section>
    <?php endif; ?>
    <?php if ($batch['status'] === 'preview' && can($actor, 'imports.commit')): ?>
        <form method="post" class="commit-bar" data-confirm="Düzeltilmiş satırlar uçuş tablosuna aktarılacak. Devam edilsin mi?"><input type="hidden" name="action" value="commit_import"><input type="hidden" name="batch_id" value="<?= $id ?>"><?= csrf_field() ?><div><strong>3. adım</strong><p>Hata kalmadığında düzeltilmiş satırları uçuş tablosuna aktar.</p></div><button class="btn btn-success" <?= $invalid ? 'disabled' : '' ?>>SQL'e Aktar</button></form>
    <?php elseif ($batch['status'] !== 'preview'): ?>
        <section class="panel notice">Bu import tamamlandı; satırlar artık salt okunur.</section>
    <?php endif;
}

function render_flight_detail(array $actor): void
{
    $id = (int)($_GET['id'] ?? 0);
    $flight = FlightService::find($id);
    if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
    $context = FlightService::context($flight);
    Authorization::require($actor, 'flights.view', $context);
    ?>
    <section class="welcome-card">
        <div><p class="eyebrow"><?= e($flight['icao_code'] . ' · ' . $flight['flight_type_name']) ?></p><h2><?= e($flight['departure_flight_number'] ?: $flight['arrival_flight_number'] ?: 'Uçuş #' . $id) ?></h2><p><?= e(($flight['tail_number'] ?: 'Kuyruk yok') . ' · Park ' . ($flight['stand'] ?: '-') . ' · ' . $flight['status']) ?></p></div>
        <div class="actions"><?php if (can($actor, 'flights.update', $context)): ?><a class="btn btn-light" href="<?= e(url_for('/flights/edit') . '?id=' . $id) ?>">Bilgileri Düzenle</a><?php endif; ?><?php if (can($actor, 'flights.assign', $context)): ?><a class="btn btn-light" href="<?= e(url_for('/flights/assign') . '?id=' . $id) ?>">Atama</a><?php endif; ?></div>
    </section>
    <?php if (can($actor, 'processes.view', $context)):
        $processes = DB::fetchAll(
            'SELECT pt.id, pt.name, pt.input_type, m.required, COALESCE(fp.state, "not_started") AS state,
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
            $canEdit = can($actor, 'processes.update', $context) && ((!$recorded && !$flightIsClosed) || $canOverride);
            ?>
            <article class="panel process-card state-<?= e($process['state']) ?>">
                <div class="section-heading"><div><p class="eyebrow"><?= $process['required'] ? 'Zorunlu' : 'Opsiyonel' ?></p><h2><?= e($process['name']) ?></h2></div><span class="badge <?= e($process['state']) ?>"><?= e($process['state']) ?></span></div>
                <?php if ($process['input_type'] === 'datetime' && $process['value_datetime']): ?><p class="process-value"><?= e(date('d.m.Y H:i:s', strtotime($process['value_datetime']))) ?></p><?php endif; ?>
                <?php if ($process['input_type'] === 'text' && trim((string)$process['value_text']) !== ''): ?><p class="process-value"><?= nl2br(e($process['value_text'])) ?></p><?php endif; ?>
                <?php if ($process['input_type'] === 'state' && $process['started_at']): ?><p class="muted">Başlangıç: <?= e(date('d.m.Y H:i:s', strtotime($process['started_at']))) ?><?php if ($process['finished_at']): ?><br>Bitiş: <?= e(date('d.m.Y H:i:s', strtotime($process['finished_at']))) ?><?php endif; ?></p><?php endif; ?>
                <?php if ($canEdit && $process['input_type'] === 'state'): ?>
                    <div class="actions"><?php foreach (['start' => 'Başlat', 'finish' => 'Bitir', 'not_used' => 'Kullanılmadı'] as $action => $label): ?><form method="post"><input type="hidden" name="action" value="save_process"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="process_type_id" value="<?= (int)$process['id'] ?>"><input type="hidden" name="process_action" value="<?= e($action) ?>"><?= csrf_field() ?><button class="btn btn-small"><?= e($label) ?></button></form><?php endforeach; ?></div>
                <?php elseif ($canEdit && $process['input_type'] === 'datetime'): ?>
                    <form method="post" class="inline-form"><input type="hidden" name="action" value="save_process"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="process_type_id" value="<?= (int)$process['id'] ?>"><input type="hidden" name="process_action" value="mark_time"><?= csrf_field() ?><input type="datetime-local" name="value_datetime" value="<?= e(datetime_local($process['value_datetime'])) ?>"><button class="btn btn-small">Saati Kaydet</button></form>
                <?php elseif ($canEdit && $process['input_type'] === 'text'): ?>
                    <form method="post"><input type="hidden" name="action" value="save_process"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="process_type_id" value="<?= (int)$process['id'] ?>"><input type="hidden" name="process_action" value="save_text"><?= csrf_field() ?><textarea name="value_text" rows="3"><?= e($process['value_text']) ?></textarea><button class="btn btn-small">Notu Kaydet</button></form>
                <?php endif; ?>
                <?php if ($canOverride && ($recorded || $process['state'] !== 'not_started')): ?><form method="post" class="reset-form" data-confirm="Bu süreç kaydı sıfırlanacak. Devam edilsin mi?"><input type="hidden" name="action" value="save_process"><input type="hidden" name="flight_id" value="<?= $id ?>"><input type="hidden" name="process_type_id" value="<?= (int)$process['id'] ?>"><input type="hidden" name="process_action" value="reset"><?= csrf_field() ?><button class="btn btn-ghost btn-small">Sıfırla</button></form><?php endif; ?>
            </article>
        <?php endforeach; ?>
        </section>
    <?php else: ?>
        <section class="panel notice">Bu uçuşun operasyon süreçlerini görüntüleme yetkiniz yok.</section>
    <?php endif; ?>
    <?php if (can($actor, 'flights.complete', $context) && in_array($flight['status'], ['scheduled', 'active'], true)): ?>
        <form method="post" class="commit-bar" data-confirm="Zorunlu süreçler kontrol edilerek uçuş tamamlanacak. Devam edilsin mi?"><input type="hidden" name="action" value="complete_flight"><input type="hidden" name="flight_id" value="<?= $id ?>"><?= csrf_field() ?><div><strong>Operasyonu tamamla</strong><p>Zorunlu süreçler bitmeden tamamlanamaz.</p></div><button class="btn btn-success">Uçuşu Tamamla</button></form>
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
function render_pagination(string $path, array $query, int $current, int $total): void
{
    if ($total <= 1) return;
    $pages = array_unique(array_merge([1], range(max(1, $current - 3), min($total, $current + 3)), [$total]));
    sort($pages);
    echo '<nav class="pagination" aria-label="Sayfalar">';
    foreach ($pages as $page) {
        $href = url_for($path) . '?' . http_build_query(array_merge($query, ['page' => $page]));
        echo '<a class="' . ($page === $current ? 'active' : '') . '" href="' . e($href) . '">' . $page . '</a>';
    }
    echo '</nav>';
}
function selected(mixed $value,mixed $expected):string{return (string)$value===(string)$expected?'selected':'';}
function checked(bool $value):string{return $value?'checked':'';}
function options_rows(array $rows,int $selectedId,string $labelKey,string $secondKey=''):void{echo '<option value="">Seçin</option>';foreach($rows as $row){$label=(string)$row[$labelKey].($secondKey!==''?' · '.(string)$row[$secondKey]:'');echo '<option value="'.(int)$row['id'].'" '.selected($selectedId,$row['id']).'>'.e($label).'</option>';}}
function role_summary(array $user):string{$codes=DB::fetchAll('SELECT DISTINCT r.name FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id WHERE urs.user_id=? ORDER BY r.name',[(int)$user['id']]);return implode(' · ',array_column($codes,'name'))?:'Rol yok';}
function scope_summary(array $user):string{if(Authorization::isGlobal($user,'dashboard.view'))return 'Global erişim';$codes=DB::fetchAll('SELECT DISTINCT a.icao_code FROM user_role_scopes urs JOIN airlines a ON a.id=urs.airline_id WHERE urs.user_id=? AND urs.scope_type="airline" ORDER BY a.icao_code',[(int)$user['id']]);$icao=implode(', ',array_column($codes,'icao_code'));return $icao!==''?'ICAO: '.$icao:'Atanmış uçuşlar';}

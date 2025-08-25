<?php
/***************************************************************
 * JobTrackr — Single-file App (PHP + SQLite)
 * Autor: Alex Oliveira — alexdevcode.com
 * Requisitos: PHP 8+, extensão PDO_SQLITE habilitada
 ***************************************************************/
declare(strict_types=1);
session_start();
date_default_timezone_set('Europe/Lisbon');

/* ---------- CONFIG ---------- */
const DB_PATH = __DIR__ . '/data.sqlite';
const STATUSES = ['Applied','Screening','Interviewing','Offer','Rejected'];

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrf_field(): string {
  return '<input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf']).'">';
}
function check_csrf(): void {
  if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(403);
    exit('Invalid CSRF token.');
  }
}

/* ---------- DB ---------- */
try {
  $pdo = new PDO('sqlite:' . DB_PATH);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec('PRAGMA foreign_keys = ON;');
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS jobs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      company TEXT NOT NULL,
      location TEXT,
      url TEXT,
      status TEXT NOT NULL DEFAULT 'Applied',
      salary TEXT,
      next_action_date TEXT,
      notes TEXT,
      created_at TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status);
    CREATE INDEX IF NOT EXISTS idx_jobs_nextdate ON jobs(next_action_date);
  ");
} catch (Throwable $e) {
  error_log('DB error: '.$e->getMessage());
  http_response_code(500);
  exit('Database error.');
}

/* ---------- HELPERS ---------- */
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function valid_date(?string $d): bool {
  if (!$d) return false;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
}
function redirect(string $to): void {
  header('Location: '.$to);
  exit;
}
function base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
  return $scheme.'://'.$host.$path.'/';
}

/* ---------- ACTIONS (POST/GET) ---------- */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $title   = trim($_POST['title'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $location= trim($_POST['location'] ?? '');
    $url     = trim($_POST['url'] ?? '');
    $status  = $_POST['status'] ?? 'Applied';
    $salary  = trim($_POST['salary'] ?? '');
    $next    = trim($_POST['next_action_date'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');

    if ($title === '' || $company === '') {
      $flash = ['type'=>'error','msg'=>'Preencha pelo menos Cargo e Empresa.'];
    } elseif (!in_array($status, STATUSES, true)) {
      $flash = ['type'=>'error','msg'=>'Status inválido.'];
    } elseif ($next !== '' && !valid_date($next)) {
      $flash = ['type'=>'error','msg'=>'Data de follow-up inválida (use YYYY-MM-DD).'];
    } else {
      $stmt = $pdo->prepare("INSERT INTO jobs (title,company,location,url,status,salary,next_action_date,notes,created_at)
                             VALUES (?,?,?,?,?,?,?,?,?)");
      $stmt->execute([$title,$company,$location,$url,$status,$salary,$next ?: null,$notes,date('c')]);
      $flash = ['type'=>'ok','msg'=>'Candidatura adicionada!'];
    }
  }

  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $title   = trim($_POST['title'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $location= trim($_POST['location'] ?? '');
    $url     = trim($_POST['url'] ?? '');
    $status  = $_POST['status'] ?? 'Applied';
    $salary  = trim($_POST['salary'] ?? '');
    $next    = trim($_POST['next_action_date'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');

    if ($id <= 0) {
      $flash = ['type'=>'error','msg'=>'ID inválido.'];
    } elseif ($title === '' || $company === '') {
      $flash = ['type'=>'error','msg'=>'Preencha pelo menos Cargo e Empresa.'];
    } elseif (!in_array($status, STATUSES, true)) {
      $flash = ['type'=>'error','msg'=>'Status inválido.'];
    } elseif ($next !== '' && !valid_date($next)) {
      $flash = ['type'=>'error','msg'=>'Data de follow-up inválida.'];
    } else {
      $stmt = $pdo->prepare("UPDATE jobs SET title=?, company=?, location=?, url=?, status=?, salary=?, next_action_date=?, notes=? WHERE id=?");
      $stmt->execute([$title,$company,$location,$url,$status,$salary,$next ?: null,$notes,$id]);
      $flash = ['type'=>'ok','msg'=>'Candidatura atualizada!'];
    }
  }

  if ($action === 'update_status') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'Applied';
    if ($id <= 0 || !in_array($status, STATUSES, true)) {
      $flash = ['type'=>'error','msg'=>'Dados de status inválidos.'];
    } else {
      $pdo->prepare("UPDATE jobs SET status=? WHERE id=?")->execute([$status,$id]);
      $flash = ['type'=>'ok','msg'=>'Status atualizado.'];
    }
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare("DELETE FROM jobs WHERE id=?")->execute([$id]);
      $flash = ['type'=>'ok','msg'=>'Candidatura removida.'];
    }
  }
}

/* ---------- EXPORTS ---------- */
if (($_GET['export'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=jobtrackr_export_'.date('Ymd_His').'.csv');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['id','title','company','location','url','status','salary','next_action_date','notes','created_at']);
  $q = $pdo->query("SELECT id,title,company,location,url,status,salary,next_action_date,notes,created_at FROM jobs ORDER BY created_at DESC");
  while ($row = $q->fetch(PDO::FETCH_ASSOC)) { fputcsv($out, $row); }
  fclose($out);
  exit;
}

if (($_GET['export'] ?? '') === 'ics') {
  header('Content-Type: text/calendar; charset=utf-8');
  header('Content-Disposition: attachment; filename=jobtrackr_followups_'.date('Ymd_His').'.ics');
  echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//JobTrackr//EN\r\nCALSCALE:GREGORIAN\r\n";
  $stmt = $pdo->query("SELECT id,title,company,next_action_date FROM jobs WHERE next_action_date IS NOT NULL AND next_action_date <> '' ORDER BY next_action_date ASC");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $uid = 'jobtrackr-'.$row['id'].'@localhost';
    $dt  = DateTime::createFromFormat('Y-m-d', $row['next_action_date']);
    $dtstart = $dt ? $dt->format('Ymd') : date('Ymd');
    $summary = 'Follow-up: '.$row['title'].' @ '.$row['company'];
    echo "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:".gmdate('Ymd\THis\Z')."\r\nDTSTART;VALUE=DATE:$dtstart\r\nSUMMARY:".str_replace(["\r","\n"], ' ', $summary)."\r\nEND:VEVENT\r\n";
  }
  echo "END:VCALENDAR\r\n";
  exit;
}

/* ---------- QUERY (listar com filtros) ---------- */
$filter_status = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM jobs WHERE 1=1";
$params = [];
if ($filter_status !== 'all' && in_array($filter_status, STATUSES, true)) {
  $sql .= " AND status = ?";
  $params[] = $filter_status;
}
if ($search !== '') {
  $sql .= " AND (title LIKE ? OR company LIKE ? OR location LIKE ?)";
  $like = '%'.$search.'%';
  array_push($params, $like, $like, $like);
}
$sql .= " ORDER BY created_at DESC";
$list = $pdo->prepare($sql);
$list->execute($params);
$jobs = $list->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#0f172a">
  <link rel="icon" href="img/jobtrackr.png">
  <title>JobTrackr — Candidaturas</title>
  <style>
    :root{
      --bg1:#0f172a; --bg2:#111827; --card:rgba(255,255,255,.9); --blur:12px;
      --txt:#0f172a; --muted:#6b7280; --brand:#2563eb; --brand2:#7c3aed;
      --ok:#16a34a; --err:#dc2626; --bd:#e5e7eb; --radius:16px; --shadow:0 10px 30px rgba(0,0,0,.15);
    }
    *{box-sizing:border-box}
    body{
      margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial,sans-serif;
      background: radial-gradient(1200px 600px at 20% 0%, rgba(59,130,246,.15), transparent 60%),
                  radial-gradient(1000px 500px at 100% 0%, rgba(124,58,237,.12), transparent 60%),
                  linear-gradient(180deg, var(--bg1), var(--bg2));
      color:#fff;
    }
    .container{max-width:1100px;margin:48px auto;padding:0 18px;animation:fadeIn .5s ease-out both}
    header.hero{
      background:linear-gradient(135deg, rgba(37,99,235,.12), rgba(124,58,237,.12));
      border:1px solid rgba(255,255,255,.12); color:#e5e7eb;
      border-radius:20px; padding:18px 20px; backdrop-filter: blur(8px);
      box-shadow: var(--shadow); position:relative; overflow:hidden;
    }
    /* BRAND (logo + título) */
    .brand{display:flex; align-items:center; gap:14px}
    .brand .logo{
      width:auto; height:40px; max-width:180px; object-fit:contain;
      filter: drop-shadow(0 2px 6px rgba(0,0,0,.25));
    }
    @media (min-width:680px){
      .brand .logo{height:48px}
    }
    header.hero::after{
      content:""; position:absolute; inset:-2px; background:
      radial-gradient(600px 120px at 20% -10%, rgba(59,130,246,.18), transparent 60%),
      radial-gradient(500px 100px at 80% -10%, rgba(124,58,237,.18), transparent 60%);
      pointer-events:none;
    }
    h1{margin:0 0 4px;font-size:28px;letter-spacing:.2px}
    .sub{color:#cbd5e1;margin:0}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-top:20px}
    .card{
      background:var(--card); color:var(--txt); border:1px solid rgba(0,0,0,.06);
      border-radius:var(--radius); box-shadow:var(--shadow); backdrop-filter: blur(var(--blur));
      transform:translateY(6px); opacity:0; animation:rise .6s ease-out forwards;
    }
    .card:nth-child(1){animation-delay:.05s}.card:nth-child(2){animation-delay:.12s}
    .card .hd{padding:18px 18px 0}
    .card .bd{padding:18px}
    label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
    input,select,textarea{
      width:100%;padding:12px 12px;border:1px solid #e6e7eb;border-radius:12px;font-size:14px;background:#fff;
      transition:border .2s, box-shadow .2s, transform .1s;
    }
    input:focus,select:focus,textarea:focus{
      outline:0;border-color:rgba(37,99,235,.6); box-shadow:0 0 0 4px rgba(37,99,235,.15);
    }
    textarea{min-height:84px;resize:vertical}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
    .btn{
      position:relative; border:0; padding:11px 16px; border-radius:12px; font-weight:700; cursor:pointer; overflow:hidden;
      transition: transform .1s ease, box-shadow .2s ease;
    }
    .btn:active{transform:translateY(1px)}
    .btn-brand{
      color:#fff; background:linear-gradient(135deg, var(--brand), var(--brand2));
      box-shadow:0 8px 18px rgba(37,99,235,.25);
    }
    .btn-ghost{background:#f8fafc;border:1px solid #e6e7eb;color:#0f172a}
    .btn-danger{background:linear-gradient(135deg,#ef4444,#dc2626); color:#fff}
    .btn:hover{box-shadow:0 10px 24px rgba(0,0,0,.12)}
    .pill{display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid #e6e7eb;font-size:12px;color:#475569;background:#fff}
    .toolbar{
      position:sticky; top:0; background:var(--card); z-index:5; display:flex;gap:10px;flex-wrap:wrap;
      align-items:center;justify-content:space-between;margin-bottom:14px;padding-top:6px;border-bottom:1px dashed #e6e7eb;
    }
    .toolbar .left{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .table-wrap{overflow-x:auto}
    table{width:100%;border-collapse:separate;border-spacing:0 10px;min-width:760px}
    th,td{text-align:left;font-size:14px;padding:10px 14px}
    thead th{color:#64748b;font-weight:700}
    tbody tr{background:#fff;border:1px solid #e6e7eb;animation:fadeRow .45s ease both}
    tbody tr td:first-child{border-radius:12px 0 0 12px}
    tbody tr td:last-child{border-radius:0 12px 12px 0}
    .status-badge{
      display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700
    }
    .status-applied{background:#eff6ff;color:#1d4ed8}
    .status-screening{background:#f0fdf4;color:#166534}
    .status-interviewing{background:#fff7ed;color:#c2410c}
    .status-offer{background:#ecfeff;color:#0e7490}
    .status-rejected{background:#fef2f2;color:#b91c1c}
    .flash{margin:16px 0;padding:12px 14px;border-radius:12px}
    .flash.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
    .flash.err{background:#fef2f2;color:#7f1d1d;border:1px solid #fecaca}
    .flash.hide{opacity:0;transform:translateY(-6px);transition:all .4s ease}
    footer.signature{
      margin:26px auto 10px; text-align:center; color:#94a3b8; font-size:13px
    }
    footer.signature a{color:#c7d2fe;text-decoration:none;border-bottom:1px dashed rgba(199,210,254,.4)}
    footer.signature a:hover{opacity:.9}
    .ripple{position:absolute;border-radius:50%;transform:scale(0);animation:ripple .6s linear;background:rgba(255,255,255,.45)}
    @keyframes ripple{to{transform:scale(4);opacity:0}}
    @keyframes fadeIn{from{opacity:0}to{opacity:1}}
    @keyframes rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
    @keyframes fadeRow{from{opacity:.0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
    @media(max-width:980px){.grid{grid-template-columns:1fr}}
    @media(max-width:640px){
      .row{grid-template-columns:1fr}
      table{min-width:600px}
      thead th:nth-child(3), tbody td:nth-child(3){display:none}
      thead th:nth-child(4), tbody td:nth-child(4){display:none}
    }
    @media (prefers-reduced-motion: reduce){
      *{animation:none !important; transition:none !important}
    }
  </style>
</head>
<body>
  <div class="container">
    <header class="hero">
      <div class="brand">
        <img class="logo" src="img/jobtrackr.png" alt="JobTrackr logo" onerror="this.style.display='none'">
        <div>
          <h1>JobTrackr</h1>
          <p class="sub">Rastreie candidaturas, status e lembretes de follow-up. Exporte CSV e <em>.ics</em> para o seu calendário.</p>
        </div>
      </div>
    </header>

    <?php if ($flash): ?>
      <div id="flash" class="flash <?= $flash['type']==='ok'?'ok':'err' ?>"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="grid">

      <!-- FORM NOVA CANDIDATURA -->
      <div class="card">
        <div class="hd"><h3>Nova candidatura</h3></div>
        <div class="bd">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="row">
              <div>
                <label>Cargo *</label>
                <input name="title" required placeholder="Ex.: Desenvolvedor PHP">
              </div>
              <div>
                <label>Empresa *</label>
                <input name="company" required placeholder="Ex.: Acme Tech">
              </div>
            </div>
            <div class="row">
              <div>
                <label>Local</label>
                <input name="location" placeholder="Lisboa / Remoto">
              </div>
              <div>
                <label>Link da vaga</label>
                <input name="url" placeholder="https://...">
              </div>
            </div>
            <div class="row">
              <div>
                <label>Status</label>
                <select name="status">
                  <?php foreach (STATUSES as $st): ?>
                  <option value="<?= e($st) ?>"><?= e($st) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label>Salário (faixa)</label>
                <input name="salary" placeholder="Ex.: €28k–€34k">
              </div>
            </div>
            <div class="row">
              <div>
                <label>Data de follow-up</label>
                <input type="date" name="next_action_date">
              </div>
              <div>
                <label>Notas</label>
                <input name="notes" placeholder="Contato, feedback, etapa, etc.">
              </div>
            </div>
            <div class="actions">
              <button class="btn btn-brand" type="submit">Adicionar</button>
              <span class="pill">CSRF ativo • SQLite</span>
            </div>
          </form>
        </div>
      </div>

      <!-- LISTAGEM / FILTROS -->
      <div class="card">
        <div class="hd"><h3>Suas candidaturas</h3></div>
        <div class="bd">
          <form method="get" class="toolbar">
            <div class="left">
              <select name="status">
                <option value="all" <?= $filter_status==='all'?'selected':'' ?>>Todas</option>
                <?php foreach (STATUSES as $st): ?>
                  <option value="<?= e($st) ?>" <?= $filter_status===$st?'selected':'' ?>><?= e($st) ?></option>
                <?php endforeach; ?>
              </select>
              <input name="q" placeholder="Buscar por cargo/empresa/local" value="<?= e($search) ?>">
              <button class="btn btn-ghost" type="submit">Filtrar</button>
            </div>
            <div class="actions">
              <a class="btn btn-ghost" href="?export=csv">Exportar CSV</a>
              <a class="btn btn-ghost" href="?export=ics">Calendário .ics</a>
            </div>
          </form>

          <?php if (!$jobs): ?>
            <p class="sub" style="color:#64748b">Sem resultados. Adicione uma candidatura ao lado ou ajuste seus filtros.</p>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead>
                <tr>
                  <th>ID</th>
                  <th>Cargo / Empresa</th>
                  <th>Local</th>
                  <th>Próx. ação</th>
                  <th>Status</th>
                  <th>Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $j): ?>
                  <?php $statusClass = 'status-'.strtolower(str_replace(' ', '-', $j['status'])); ?>
                  <tr>
                    <td>#<?= (int)$j['id'] ?></td>
                    <td>
                      <div><strong><?= e($j['title']) ?></strong></div>
                      <div class="sub" style="color:#64748b"><?= e($j['company']) ?></div>
                      <?php if ($j['url']): ?>
                        <div><a href="<?= e($j['url']) ?>" target="_blank" rel="noopener">Abrir vaga</a></div>
                      <?php endif; ?>
                      <?php if ($j['salary']): ?>
                        <div class="sub" style="color:#64748b">Faixa: <?= e($j['salary']) ?></div>
                      <?php endif; ?>
                      <?php if ($j['notes']): ?>
                        <div class="sub" style="color:#64748b">Notas: <?= e($j['notes']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= e($j['location'] ?: '—') ?></td>
                    <td><?= e($j['next_action_date'] ?: '—') ?></td>
                    <td><span class="status-badge <?= e($statusClass) ?>"><?= e($j['status']) ?></span></td>
                    <td>
                      <form method="post" style="display:inline-block;margin-right:6px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                        <select name="status" onchange="this.form.submit()">
                          <?php foreach (STATUSES as $st): ?>
                            <option value="<?= e($st) ?>" <?= $j['status']===$st?'selected':'' ?>><?= e($st) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </form>
                      <button class="btn btn-ghost" onclick="toggleEdit(<?= (int)$j['id'] ?>)">Editar</button>
                      <form method="post" style="display:inline-block" onsubmit="return confirm('Remover esta candidatura?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                        <button class="btn btn-danger" type="submit">Excluir</button>
                      </form>
                    </td>
                  </tr>
                  <tr id="edit-<?= (int)$j['id'] ?>" style="display:none">
                    <td colspan="6">
                      <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                        <div class="row">
                          <div>
                            <label>Cargo *</label>
                            <input name="title" value="<?= e($j['title']) ?>" required>
                          </div>
                          <div>
                            <label>Empresa *</label>
                            <input name="company" value="<?= e($j['company']) ?>" required>
                          </div>
                        </div>
                        <div class="row">
                          <div>
                            <label>Local</label>
                            <input name="location" value="<?= e($j['location']) ?>">
                          </div>
                          <div>
                            <label>Link da vaga</label>
                            <input name="url" value="<?= e($j['url']) ?>">
                          </div>
                        </div>
                        <div class="row">
                          <div>
                            <label>Status</label>
                            <select name="status">
                              <?php foreach (STATUSES as $st): ?>
                                <option value="<?= e($st) ?>" <?= $j['status']===$st?'selected':'' ?>><?= e($st) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div>
                            <label>Salário (faixa)</label>
                            <input name="salary" value="<?= e($j['salary']) ?>">
                          </div>
                        </div>
                        <div class="row">
                          <div>
                            <label>Data de follow-up</label>
                            <input type="date" name="next_action_date" value="<?= e($j['next_action_date']) ?>">
                          </div>
                          <div>
                            <label>Notas</label>
                            <input name="notes" value="<?= e($j['notes']) ?>">
                          </div>
                        </div>
                        <div class="actions">
                          <button class="btn btn-brand" type="submit">Salvar edição</button>
                          <button class="btn btn-ghost" type="button" onclick="toggleEdit(<?= (int)$j['id'] ?>)">Cancelar</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <p class="sub" style="margin-top:18px;color:#94a3b8">
      Dica: exporte <strong>.ics</strong> e importe no Google Calendar/Outlook para lembrar dos follow-ups. 😉
    </p>

    <footer class="signature">
      <span>Feito por</span> <a href="https://alexdevcode.com" target="_blank" rel="noopener">Alex Oliveira</a> — © <?= date('Y') ?>
    </footer>
  </div>

  <script>
    function toggleEdit(id){
      const row = document.getElementById('edit-'+id);
      if (!row) return;
      row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
      row.scrollIntoView({behavior:'smooth', block:'center'});
    }
    (function(){
      const f = document.getElementById('flash');
      if(!f) return;
      setTimeout(()=>{ f.classList.add('hide'); }, 2200);
      setTimeout(()=>{ if (f && f.parentNode) f.parentNode.removeChild(f); }, 2700);
    })();
    document.addEventListener('click', function(e){
      const btn = e.target.closest('.btn');
      if(!btn) return;
      const circle = document.createElement('span');
      const diameter = Math.max(btn.clientWidth, btn.clientHeight);
      const radius = diameter / 2;
      circle.style.width = circle.style.height = `${diameter}px`;
      circle.style.left = `${e.clientX - (btn.getBoundingClientRect().left + radius)}px`;
      circle.style.top = `${e.clientY - (btn.getBoundingClientRect().top + radius)}px`;
      circle.classList.add('ripple');
      const prev = btn.getElementsByClassName('ripple')[0];
      if (prev) prev.remove();
      btn.appendChild(circle);
    }, false);
  </script>
</body>
</html>

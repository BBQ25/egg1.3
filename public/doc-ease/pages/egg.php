<?php
// EggSort Pro - Single-file PHP/HTML dashboard (MVP)
// -----------------------------------------------------------------------------
// How to run:
// 1) Save this file as `dashboard.php` in your PHP-enabled server.
// 2) Open http://localhost/dashboard.php
// 3) Optional: click the CSV button to download the current table as CSV.
// -----------------------------------------------------------------------------

// --- SETTINGS ----------------------------------------------------------------
date_default_timezone_set('UTC');

// Thresholds in grams (g)
$thresholds = [
  ['category' => 'Pewee',      'min' => 40.1, 'max' => 44.0],
  ['category' => 'Pullet',     'min' => 44.1, 'max' => 49.0],
  ['category' => 'Small',      'min' => 49.1, 'max' => 54.0],
  ['category' => 'Medium',     'min' => 54.1, 'max' => 64.0],
  ['category' => 'Large',      'min' => 64.1, 'max' => 68.0],
  ['category' => 'Extra-Large','min' => 68.1, 'max' => 79.0],
  ['category' => 'Jumbo',      'min' => 79.1, 'max' => 90.0],
  // Reject rule is handled specially below
];

// --- HELPERS -----------------------------------------------------------------
function categorize_egg($weight) {
  global $thresholds;
  if ($weight <= 40.0 || $weight >= 90.1) return 'Reject';
  foreach ($thresholds as $t) {
    if ($weight >= $t['min'] && $weight <= $t['max']) return $t['category'];
  }
  return 'Reject';
}

function lane_for_category($category) {
  $map = [
    'Pewee' => 'A1', 'Pullet' => 'A1', 'Small' => 'A2', 'Medium' => 'A3',
    'Large' => 'A4', 'Extra-Large' => 'A5', 'Jumbo' => 'A6', 'Reject' => 'R1'
  ];
  return $map[$category] ?? 'A1';
}

function status_from_category($category) { return $category === 'Reject' ? 'Rejected' : 'Accepted'; }
function pct($num, $den) { return $den ? round($num / $den * 100, 1) : 0; }
function trend_span($delta) {
  $sign = $delta >= 0 ? '+' : '';
  $cls  = $delta >= 0 ? 'trend-up' : 'trend-down';
  return "<span class=\"$cls\">$sign" . number_format($delta, 1) . "%</span>";
}

// --- SYNTHETIC SAMPLE DATA ----------------------------------------------------
// Create a small, consistent pseudo-random dataset seeded by date so refreshes
// look stable across a single day.
$seed = intval(date('Ymd'));
mt_srand($seed);

$rows = [];
$now = time();
$totalItems = 120; // eggs scanned today (for demo)
for ($i = 0; $i < $totalItems; $i++) {
  $ts = $now - mt_rand(0, 60*60*6); // last 6 hours
  // Weight distribution centered ~62g with some outliers
  $w = max(30, min(100, 62 + mt_rand(-180, 180)/10));
  $cat = categorize_egg($w);
  $status = status_from_category($cat);
  $rows[] = [
    'Egg_ID' => sprintf('EGG-%05d', 12000 + $i + 1),
    'Timestamp' => date('Y-m-d H:i:s', $ts),
    'Weight' => round($w, 1),
    'Category' => $cat,
    'Lane' => lane_for_category($cat),
    'Confidence' => mt_rand(930, 985)/10, // 93.0-98.5
    'Status' => $status,
    'Notes' => ($status === 'Rejected' && mt_rand(0, 100) < 25) ? 'Visible crack' : ''
  ];
}

// Sort by time desc (most recent first)
usort($rows, fn($a, $b) => strcmp($b['Timestamp'], $a['Timestamp']));

// --- FILTERING ---------------------------------------------------------------
$categoryFilter = $_GET['category'] ?? 'All';
if ($categoryFilter !== 'All') {
  $rows = array_values(array_filter($rows, fn($r) => $r['Category'] === $categoryFilter));
}

// --- METRICS -----------------------------------------------------------------
$total = count($rows);
$accepted = count(array_filter($rows, fn($r) => $r['Status'] === 'Accepted'));
$rejected = $total - $accepted;
$avgWeight = $total ? array_sum(array_column($rows, 'Weight')) / $total : 0;

// Throughput demo metric (eggs/min) derived loosely from volume
$throughput = max(120, min(220, round($total / 120 * 180)));
$throughputDelta = 5.6;
$avgWeightDev = 2.0;
$deviceStatus = 'Online';
$deviceId = 'SortBot-002';

// Category distribution
$cats = ['Pewee','Pullet','Small','Medium','Large','Extra-Large','Jumbo','Reject'];
$dist = array_fill_keys($cats, 0);
foreach ($rows as $r) { $dist[$r['Category']]++; }

// --- CSV EXPORT --------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="egg_database_'.date('Ymd_His').'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, array_keys($rows[0] ?? [
    'Egg_ID' => '', 'Timestamp' => '', 'Weight' => '', 'Category' => '', 'Lane' => '', 'Confidence' => '', 'Status' => '', 'Notes' => ''
  ]));
  foreach ($rows as $r) { fputcsv($out, $r); }
  fclose($out);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>EggSort Pro - Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg: #f4f2ea;
      --surface: #ffffff;
      --surface-2: #fdfbf6;
      --muted: #5b6472;
      --text: #0f172a;
      --primary: #0f766e;
      --primary-2: #0b5f59;
      --success: #16a34a;
      --danger: #dc2626;
      --warn: #f59e0b;
      --border: #e7e0d6;
      --sidebar: #0c2f2a;
      --sidebar-2: #123c36;
      --chip: #e7f8f2;
      --radius: 16px;
      --shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
      --shadow-soft: 0 8px 20px rgba(15, 118, 110, 0.12);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      background:
        radial-gradient(1200px 500px at 12% -10%, #e6f6f1 0%, rgba(230,246,241,0) 60%),
        radial-gradient(1000px 500px at 100% 0%, #fff3dd 0%, rgba(255,243,221,0) 60%),
        var(--bg);
      font-family:"Figtree",system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
      color:var(--text);
    }

    .layout{display:grid;grid-template-columns:280px 1fr;min-height:100vh;width:100%}
    .content-area{min-width:0;display:flex;flex-direction:column}
    aside{
      background:linear-gradient(180deg,var(--sidebar),var(--sidebar-2));
      color:#e5e7eb;
      padding:22px 18px;
      display:flex;
      flex-direction:column;
      gap:18px;
      position:relative;
    }
    .brand{display:flex;align-items:center;gap:12px}
    .brand .logo{
      width:40px;height:40px;border-radius:12px;display:grid;place-items:center;
      background:linear-gradient(140deg,#fef3c7,#a7f3d0);color:#0b5f59;font-weight:800;
      box-shadow:0 8px 14px rgba(0,0,0,0.18);
    }
    .brand-title{font-weight:700;letter-spacing:0.2px}
    .brand-sub{font-size:12px;color:#9fb2b3}
    nav{display:grid;gap:6px}
    nav a{
      display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:12px;
      color:#e5e7eb;text-decoration:none;border:1px solid transparent;transition:all .15s ease;
    }
    nav a.active{background:rgba(15,118,110,.35);border-color:rgba(255,255,255,.12)}
    nav a:hover{background:rgba(255,255,255,.08)}
    nav .icon{width:18px;height:18px;border-radius:6px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.16)}
    .sidebar-footer{margin-top:auto;color:#9ca3af;font-size:12px}

    header{
      background:rgba(255,255,255,.88);
      box-shadow:var(--shadow);
      padding:12px 18px;
      position:sticky;top:0;z-index:10;
      backdrop-filter:blur(10px);
      border-bottom:1px solid rgba(15,23,42,0.06);
    }
    .topbar{display:flex;align-items:center;gap:14px;max-width:1280px;margin:0 auto;width:100%}
    .topbar .brand{margin:0}
    .search{flex:1;min-width:220px}
    .search input{
      width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:12px;
      background:#fff;box-shadow:inset 0 1px 2px rgba(15,23,42,0.06);
    }
    .top-actions{display:flex;align-items:center;gap:10px}
    .pill{
      display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;
      background:#e7f8f2;border:1px solid #c9efe3;color:var(--primary-2);font-size:12px;font-weight:700;
    }
    .user{margin-left:auto;display:flex;align-items:center;gap:10px}
    .avatar{width:36px;height:36px;border-radius:999px;background:#c7f0e4;display:grid;place-items:center;font-weight:700;color:#0b5f59}
    .user-meta{display:flex;flex-direction:column;line-height:1.1}
    .user-name{font-weight:700;font-size:14px}

    main{padding:22px 22px 32px;max-width:1280px;margin:0 auto;width:100%}
    .page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
    .eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);font-weight:600}
    .h1{font-size:22px;font-weight:800;margin:6px 0 6px}
    .page-actions{display:flex;gap:8px;align-items:center}

    .kpis{display:grid;grid-template-columns:repeat(5,minmax(160px,1fr));gap:14px}
    .card{
      background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow);padding:16px;
    }
    .kpi-title{font-size:12px;color:var(--muted);margin-bottom:6px}
    .kpi-value{font-size:26px;font-weight:800;letter-spacing:-0.3px}
    .kpi-sub{font-size:12px;color:var(--muted)}
    .dot{width:8px;height:8px;border-radius:999px;display:inline-block;margin-right:6px}
    .dot.green{background:var(--success)}
    .badge{
      padding:4px 8px;border-radius:999px;background:#ecfdf5;color:#065f46;
      font-size:12px;font-weight:700;border:1px solid #d1fae5;
    }
    .badge.red{background:#fef2f2;border-color:#fee2e2;color:#991b1b}

    .trend-up{color:var(--success);font-weight:700}
    .trend-down{color:var(--danger);font-weight:700}

    .grid{display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-top:14px}

    .section-title{font-weight:800;margin-bottom:8px}

    table{width:100%;border-collapse:separate;border-spacing:0 8px}
    thead th{font-size:12px;color:var(--muted);text-align:left;padding:0 10px;text-transform:uppercase;letter-spacing:0.6px}
    tbody tr{background:var(--surface);border:1px solid var(--border);transition:transform .12s ease, box-shadow .12s ease}
    tbody tr:hover{transform:translateY(-1px);box-shadow:0 8px 18px rgba(15,23,42,0.08)}
    tbody td{padding:12px 10px;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
    tbody tr:first-child td{border-top:1px solid var(--border)}
    tbody tr td:first-child{border-left:1px solid var(--border);border-radius:12px 0 0 12px}
    tbody tr td:last-child{border-right:1px solid var(--border);border-radius:0 12px 12px 0}

    .status{padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;display:inline-block}
    .status.accepted{color:#065f46;background:#d1fae5;border:1px solid #a7f3d0}
    .status.rejected{color:#991b1b;background:#fee2e2;border:1px solid #fecaca}

    .toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .btn{
      display:inline-flex;align-items:center;gap:8px;border:1px solid var(--border);background:#fff;
      padding:8px 12px;border-radius:10px;text-decoration:none;color:var(--text);font-size:14px;font-weight:700;
    }
    .btn.primary{background:var(--primary);color:#fff;border-color:var(--primary-2);box-shadow:var(--shadow-soft)}
    .btn.ghost{background:transparent}
    .btn:hover{transform:translateY(-1px)}
    select, .input{border:1px solid var(--border);background:#fff;border-radius:10px;padding:8px 10px}

    .matrix{width:100%;border-collapse:collapse}
    .matrix th,.matrix td{border:1px solid var(--border);padding:8px 10px;font-size:13px}
    .matrix thead th{background:#faf7f0}

    .audit{display:grid;gap:8px}
    .log{padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);font-size:13px}
    .muted{color:var(--muted)}

    @media(max-width:1300px){
      .kpis{grid-template-columns:repeat(4,1fr)}
    }
    @media(max-width:1100px){
      .kpis{grid-template-columns:repeat(3,1fr)}
      .grid{grid-template-columns:1fr}
    }
    @media(max-width:900px){
      .kpis{grid-template-columns:repeat(2,1fr)}
    }
    @media(max-width:980px){
      .layout{grid-template-columns:1fr}
      aside{position:relative}
      nav{grid-auto-flow:column;grid-auto-columns:max-content;overflow-x:auto;padding-bottom:6px}
      nav a{white-space:nowrap}
      .topbar{flex-wrap:wrap}
      .search{order:3;width:100%}
      .top-actions{order:2;width:100%;justify-content:flex-start}
      .user{order:1;margin-left:0}
    }
    @media(max-width:640px){
      main{padding:16px}
      .kpi-value{font-size:22px}
      .page-actions{width:100%;justify-content:flex-start}
    }
  </style>
</head>
<body>
<div class="layout">
    <aside>
    <div class="brand">
      <div class="logo">EP</div>
      <div>
        <div class="brand-title">EggSort Pro</div>
        <div class="brand-sub">Smart grading console</div>
      </div>
    </div>
    <nav>
      <a class="active" href="#"><span class="icon"></span>Dashboard</a>
      <a href="#"><span class="icon"></span>Live Weighing</a>
      <a href="#"><span class="icon"></span>Sorting Rules</a>
      <a href="#"><span class="icon"></span>Egg Database</a>
      <a href="#"><span class="icon"></span>Reports</a>
      <a href="#"><span class="icon"></span>Devices</a>
      <a href="#"><span class="icon"></span>Users & Roles</a>
      <a href="#"><span class="icon"></span>Settings</a>
      <a href="#"><span class="icon"></span>Audit Logs</a>
    </nav>
    <div class="sidebar-footer">(c) <?=date('Y')?> Api5o</div>
  </aside>

  <div class="content-area">
        <header>
      <div class="topbar">
        <div class="brand">
          <div class="logo">EP</div>
          <div>
            <div class="brand-title">EggSort Pro</div>
            <div class="brand-sub">Device <?=$deviceId?></div>
          </div>
        </div>
        <div class="search"><input type="search" placeholder="Search eggs, lanes, IDs" aria-label="Search" /></div>
        <div class="top-actions">
          <span class="pill"><span class="dot green"></span><?=$deviceStatus?></span>
          <a class="btn ghost" href="#">Alerts</a>
          <a class="btn ghost" href="#">Support</a>
        </div>
        <div class="user">
          <div class="avatar">JD</div>
          <div class="user-meta">
            <div class="user-name">John Doe</div>
            <div class="muted">Supervisor</div>
          </div>
        </div>
      </div>
    </header>

        <main>
      <div class="page-head">
        <div>
          <div class="eyebrow">Live production overview</div>
          <div class="h1">Automated Poultry Egg Weighing and Sorting Device</div>
          <div class="muted">Shift A | Last sync <?=date('H:i')?> UTC</div>
        </div>
        <div class="page-actions">
          <a class="btn primary" href="#database">Jump to Database</a>
          <a class="btn ghost" href="#thresholds">Sorting Thresholds</a>
        </div>
      </div>

      <!-- KPI CARDS -->
      <div class="kpis">
        <div class="card">
          <div class="kpi-title">Total Eggs Today</div>
          <div class="kpi-value"><?=number_format($total)?></div>
          <div class="kpi-sub">Updated <?=date('H:i')?> - <?=trend_span(8.2)?></div>
        </div>
        <div class="card">
          <div class="kpi-title">Accepted vs Rejected</div>
          <div class="kpi-value"><?=number_format($accepted)?> &nbsp; <span class="muted">vs</span> &nbsp; <?=number_format($rejected)?></div>
          <div class="kpi-sub">Acceptance <?=pct($accepted, max(1,$total))?>%</div>
        </div>
        <div class="card">
          <div class="kpi-title">Average Weight (g)</div>
          <div class="kpi-value"><?=number_format($avgWeight,1)?> g</div>
          <div class="kpi-sub">Std Dev <?=$avgWeightDev?> g</div>
        </div>
        <div class="card">
          <div class="kpi-title">Throughput (eggs/min)</div>
          <div class="kpi-value"><?=$throughput?></div>
          <div class="kpi-sub"><?=trend_span($throughputDelta)?></div>
        </div>
        <div class="card">
          <div class="kpi-title">Device Status</div>
          <div class="kpi-value"><span class="dot green"></span><?=$deviceStatus?></div>
          <div class="kpi-sub"><?=$deviceId?></div>
        </div>
      </div>

      <div class="grid">
        <!-- LEFT: Charts + Database -->
        <div class="left">
          <div class="card">
            <div class="section-title">Category Distribution</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:center">
              <canvas id="distChart" height="140"></canvas>
              <div>
                <?php foreach ($cats as $c): ?>
                  <div style="display:flex;justify-content:space-between;margin:6px 0">
                    <div class="muted"><?=$c?></div>
                    <div><strong><?=pct($dist[$c], max(1,$total))?>%</strong></div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="card" id="database">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
              <div class="section-title">Egg Database</div>
              <form class="toolbar" method="get">
                <label class="muted">Category</label>
                <select name="category" onchange="this.form.submit()">
                  <?php $opt = array_merge(['All'],$cats); foreach ($opt as $o): ?>
                    <option value="<?=$o?>" <?=$o===$categoryFilter?'selected':''?>><?=$o?></option>
                  <?php endforeach; ?>
                </select>
                <a class="btn ghost" href="?<?=http_build_query(array_merge($_GET,['export'=>'csv']))?>">Export CSV</a>
              </form>
            </div>
            <div style="overflow:auto">
              <table>
                <thead>
                  <tr>
                    <th>Egg_ID</th>
                    <th>Timestamp</th>
                    <th>Weight (g)</th>
                    <th>Category</th>
                    <th>Sorting Lane/Bin</th>
                    <th>Confidence (%)</th>
                    <th>Status</th>
                    <th>Notes</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($rows,0,30) as $r): ?>
                  <tr>
                    <td><?=$r['Egg_ID']?></td>
                    <td><?=$r['Timestamp']?></td>
                    <td><?=$r['Weight']?></td>
                    <td><?=$r['Category']?></td>
                    <td><?=$r['Lane']?></td>
                    <td><?=number_format($r['Confidence'],1)?></td>
                    <td>
                      <?php if ($r['Status']==='Accepted'): ?>
                        <span class="status accepted">Accepted</span>
                      <?php else: ?>
                        <span class="status rejected">Rejected</span>
                      <?php endif; ?>
                    </td>
                    <td><?=$r['Notes']?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <div class="muted" style="margin-top:8px">Showing <?=min(30,$total)?> of <?=$total?> records.</div>
            </div>
          </div>
        </div>

        <!-- RIGHT: Thresholds + Roles + Audit -->
        <div class="right" style="display:grid;gap:14px">
          <div class="card" id="thresholds">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <div class="section-title">Sorting Thresholds</div>
              <a class="btn ghost" href="#">Test Rule</a>
            </div>
            <table class="matrix" style="margin-top:8px">
              <thead>
                <tr><th>Category</th><th>Min (g)</th><th>Max (g)</th></tr>
              </thead>
              <tbody>
                <?php foreach ($thresholds as $t): ?>
                  <tr>
                    <td><?=$t['category']?></td>
                    <td><?=number_format($t['min'],1)?></td>
                    <td><?=number_format($t['max'],1)?></td>
                  </tr>
                <?php endforeach; ?>
                  <tr>
                    <td>Reject</td>
                    <td>&le; 40.0</td>
                    <td>&ge; 90.1</td>
                  </tr>
              </tbody>
            </table>
          </div>

          <div class="card">
            <div class="section-title">Users & Roles</div>
            <table class="matrix">
              <thead>
                <tr>
                  <th>Role</th>
                  <th>View</th>
                  <th>Edit</th>
                  <th>Export</th>
                  <th>Configure</th>
                  <th>Delete</th>
                </tr>
              </thead>
              <tbody>
                <?php $roles=[
                  ['Admin',[1,1,1,1,1]],
                  ['Supervisor',[1,1,1,1,0]],
                  ['Technician',[1,1,0,1,0]],
                  ['Operator',[1,0,0,0,0]],
                  ['Viewer',[1,0,0,0,0]]
                ];
                foreach ($roles as $row): ?>
                  <tr>
                    <td><?=$row[0]?></td>
                    <?php foreach ($row[1] as $flag): ?>
                      <td style="text-align:center"><?=$flag?'Yes':''?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="card">
            <div class="section-title">Audit Log</div>
            <div class="audit">
              <div class="log"><strong>Supervisor</strong> updated Medium threshold. <span class="muted">- <?=date('Y-m-d')?> 09:07</span></div>
              <div class="log"><strong>Technician</strong> calibrated load cell. <span class="muted">- <?=date('Y-m-d')?> 08:31</span></div>
              <div class="log"><strong>Device</strong> SortBot-002 connected. <span class="muted">- <?=date('Y-m-d')?> 08:10</span></div>
            </div>
          </div>
        </div>
      </div>

    </main>
  </div>
</div>

<!-- Chart.js for donut chart (lightweight CDN) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  const ctx = document.getElementById('distChart');
  if (ctx) {
    const labels = <?=json_encode($cats)?>;
    const data = <?=json_encode(array_values($dist))?>;
    const colors = ['#0f766e', '#14b8a6', '#22c55e', '#84cc16', '#f59e0b', '#f97316', '#ef4444', '#7f1d1d'];
    new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels,
        datasets: [{
          data,
          backgroundColor: colors,
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        plugins: {
          legend: {
            position: 'bottom',
            labels: { usePointStyle: true, boxWidth: 10, padding: 16 }
          }
        },
        cutout: '62%',
      }
    });
  }
</script>
</body>
</html>


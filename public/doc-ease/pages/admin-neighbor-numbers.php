<?php include '../layouts/session.php'; ?>
<?php require_role('admin'); ?>
<?php include '../layouts/main.php'; ?>

<?php
    $nnCssVer = '1';
    $nnCssPath = __DIR__ . '/../assets/css/neighbor-numbers.css';
    if (is_file($nnCssPath)) $nnCssVer = (string) filemtime($nnCssPath);

    $nnJsVer = '1';
    $nnJsPath = __DIR__ . '/../assets/js/neighbor-numbers.js';
    if (is_file($nnJsPath)) $nnJsVer = (string) filemtime($nnJsPath);

    $nnIsSuperadmin = current_user_is_superadmin();

    $nnScriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $nnBaseDir = rtrim(str_replace('\\', '/', dirname($nnScriptName)), '/');
    if ($nnBaseDir === '.' || $nnBaseDir === '/') {
        $nnBaseDir = '';
    }
    if (substr($nnBaseDir, -6) === '/pages') {
        $nnBaseDir = substr($nnBaseDir, 0, -6);
    }
    if ($nnBaseDir !== '' && $nnBaseDir[0] !== '/') {
        $nnBaseDir = '/' . $nnBaseDir;
    }
    $nnApiUrl = ($nnBaseDir !== '' ? $nnBaseDir : '') . '/includes/neighbor_numbers_store.php';
?>

<head>
    <title>Neighbor Numbers | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
    <link href="assets/css/neighbor-numbers.css?v=<?php echo urlencode($nnCssVer); ?>" rel="stylesheet" type="text/css" />
</head>

<body>
<div class="wrapper">
    <?php include '../layouts/menu.php'; ?>

    <div class="content-page">
        <div class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box">
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="admin-dashboard.php">Admin</a></li>
                                    <li class="breadcrumb-item active">Neighbor Numbers</li>
                                </ol>
                            </div>
                            <h4 class="page-title">Neighbor Numbers Predictor</h4>
                        </div>
                    </div>
                </div>

                <div class="nn-hero mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <h2 class="nn-title mb-1">Generate table combinations and predict the next set.</h2>
                            <div class="nn-subtitle" id="nnTableSupportText">Default table sizes: by 3, 4, 5, 6, 7. Value range is limited to 0 to 55.</div>
                        </div>
                        <div class="nn-pillset">
                            <span class="nn-pill" id="nnTableSizesPill"><i class="ri-table-line" aria-hidden="true"></i> by 3-7</span>
                            <span class="nn-pill"><i class="ri-hashtag" aria-hidden="true"></i> values 0-55</span>
                            <span class="nn-pill"><i class="ri-bar-chart-2-line" aria-hidden="true"></i> top 5 predictive numbers</span>
                        </div>
                    </div>
                </div>

                <div class="card nn-card mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <h4 class="header-title mb-0">Table Wizards</h4>
                            <div class="text-muted small" id="nnWizardTableMeta">Choose a table wizard. Setup/Settings are tracked per table.</div>
                        </div>
                        <div class="d-flex flex-wrap gap-2" id="nnWizardTableList"></div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="card nn-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h4 class="header-title mb-0">Table Setup</h4>
                                    <span class="text-muted small">History-aware</span>
                                </div>

                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                    <div class="btn-group btn-group-sm nn-wizard-toggle" role="group" aria-label="Neighbor Numbers wizard sections">
                                        <button type="button" class="btn btn-primary" id="nnWizardSetupBtn" aria-pressed="true">Setup</button>
                                        <button type="button" class="btn btn-outline-primary" id="nnWizardSettingsBtn" aria-pressed="false">Settings</button>
                                    </div>
                                    <div class="small text-muted" id="nnWizardMeta">Setup step: configure table key and run analysis.</div>
                                </div>

                                <div class="row g-2 nn-wizard-section" data-nn-wizard-section="setup">
                                    <div class="col-4">
                                        <label class="form-label" for="nnTableSize">Table by</label>
                                        <select class="form-select" id="nnTableSize">
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5">5</option>
                                            <option value="6" selected>6</option>
                                            <option value="7">7</option>
                                        </select>
                                        <div class="form-text" id="nnRepeatRuleHint">No repeated numbers for this table size.</div>
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="nnMin">Min Value</label>
                                        <input class="form-control" id="nnMin" type="number" value="0" min="0" max="55" step="1">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="nnMax">Max Value</label>
                                        <input class="form-control" id="nnMax" type="number" value="55" min="0" max="55" step="1">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="nnAlgorithm">Prediction Algorithm</label>
                                        <select class="form-select" id="nnAlgorithm">
                                            <option value="random_forest">Random Forest</option>
                                            <option value="xgboost" selected>XGBoost</option>
                                            <option value="neural_network">Neural Networks</option>
                                            <option value="linear">Linear</option>
                                            <option value="knn">KNN</option>
                                            <option value="naive_bayes">Naive Bayes</option>
                                            <option value="sma">Simple Moving Average (SMA)</option>
                                            <option value="sgma">Stability-gated Moving Average (SGMA)</option>
                                        </select>
                                        <div class="form-text">All algorithm outputs are recalculated and saved for future reference whenever history updates.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="nnAccuracyStyle">Accuracy Style</label>
                                        <select class="form-select" id="nnAccuracyStyle">
                                            <option value="hybrid" selected>Hybrid (Stable + Trend Chase)</option>
                                            <option value="balanced">Balanced</option>
                                            <option value="conservative">Conservative</option>
                                            <option value="momentum">Momentum</option>
                                            <option value="exploratory">Exploratory</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="nn-wizard-section d-none" data-nn-wizard-section="settings">
                                    <?php if ($nnIsSuperadmin): ?>
                                    <div class="alert alert-light border mt-3 mb-0" role="alert" id="nnPolicyPanel">
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                            <div class="fw-semibold">Superadmin Table Policy</div>
                                            <span class="badge bg-info" id="nnPolicyStatusBadge">Policy editable</span>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label mb-1" for="nnAllowedSizesInput">Allowed table sizes</label>
                                            <input class="form-control form-control-sm" id="nnAllowedSizesInput" type="text" placeholder="e.g. 3,4,5,6,7,8,9">
                                            <div class="form-text">Comma-separated integers from 2 to 20.</div>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label mb-1">Allow repeated numbers on sizes</label>
                                            <div class="d-flex flex-wrap gap-2" id="nnRepeatSizeChecks"></div>
                                        </div>
                                        <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                            <button class="btn btn-sm btn-outline-primary" id="nnSavePolicyBtn" type="button">
                                                <i class="ri-save-3-line me-1" aria-hidden="true"></i>
                                                Save Policy
                                            </button>
                                            <span class="text-muted small" id="nnPolicyMeta">Policy not loaded yet.</span>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-light border mt-3 mb-0" role="alert">
                                        <div class="fw-semibold">Table Policy</div>
                                        <div class="small mt-1 text-muted" id="nnPolicyReadOnlyMeta">Managed by superadmin. Available table sizes and repeat rules are applied automatically.</div>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex flex-wrap gap-2 mt-3 nn-wizard-section" data-nn-wizard-section="setup">
                                    <button class="btn btn-primary" id="nnGenerateBtn" type="button">
                                        <i class="ri-refresh-line me-1" aria-hidden="true"></i>
                                        Refresh Analysis
                                    </button>
                                    <button class="btn btn-outline-primary" id="nnPredictBtn" type="button">
                                        <i class="ri-line-chart-line me-1" aria-hidden="true"></i>
                                        Predict Next
                                    </button>
                                    <button class="btn btn-outline-danger" id="nnClearHistoryBtn" type="button">
                                        <i class="ri-delete-bin-6-line me-1" aria-hidden="true"></i>
                                        Clear History
                                    </button>
                                </div>

                                <div class="mt-3 nn-wizard-section" data-nn-wizard-section="setup">
                                    <label class="form-label" for="nnManualCombo">Manual Combination</label>
                                    <input class="form-control" id="nnManualCombo" type="text" placeholder="e.g. 1, 4, 7, 9, 12, 18">
                                    <div class="form-text" id="nnManualHint">
                                        Enter exactly <code>by</code> numbers within the selected range. Repeat behavior follows the active table policy.
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                        <button class="btn btn-outline-success btn-sm" id="nnAddManualBtn" type="button">
                                            <i class="ri-add-line me-1" aria-hidden="true"></i>
                                            Add to History
                                        </button>
                                        <button class="btn btn-outline-secondary btn-sm" id="nnClearManualBtn" type="button">
                                            <i class="ri-eraser-line me-1" aria-hidden="true"></i>
                                            Clear Input
                                        </button>
                                    </div>
                                </div>

                                <div class="d-flex flex-wrap gap-2 mt-2 nn-wizard-section d-none" data-nn-wizard-section="settings">
                                    <button class="btn btn-outline-secondary" id="nnExportJsonBtn" type="button">
                                        <i class="ri-file-code-line me-1" aria-hidden="true"></i>
                                        Export JSON
                                    </button>
                                    <button class="btn btn-outline-secondary" id="nnExportCsvBtn" type="button">
                                        <i class="ri-file-list-3-line me-1" aria-hidden="true"></i>
                                        Export CSV
                                    </button>
                                    <button class="btn btn-outline-success" id="nnImportBtn" type="button">
                                        <i class="ri-upload-2-line me-1" aria-hidden="true"></i>
                                        Import CSV/JSON/XLSX
                                    </button>
                                    <input class="d-none" id="nnImportFile" type="file" accept=".json,.csv,.xlsx,application/json,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                                </div>
                                <div class="d-flex flex-wrap gap-2 mt-2 nn-wizard-section d-none" data-nn-wizard-section="settings">
                                    <button class="btn btn-outline-dark" id="nnExportAllJsonBtn" type="button">
                                        <i class="ri-file-copy-2-line me-1" aria-hidden="true"></i>
                                        Export All Keys (JSON)
                                    </button>
                                    <button class="btn btn-outline-dark" id="nnImportAllJsonBtn" type="button">
                                        <i class="ri-folder-upload-line me-1" aria-hidden="true"></i>
                                        Import All Keys (JSON)
                                    </button>
                                    <input class="d-none" id="nnImportAllFile" type="file" accept=".json,application/json">
                                </div>
                                <div class="form-text mt-2 nn-wizard-section d-none" data-nn-wizard-section="settings">
                                    JSON/CSV/XLSX import applies to the active key (<code>by + min + max</code>). Use All Keys buttons for full backup/restore.
                                </div>

                                <div class="alert alert-light border mt-3 mb-0 nn-wizard-section d-none" role="alert" data-nn-wizard-section="settings">
                                    <div class="fw-semibold">Spreadsheet Cell Grid</div>
                                    <div class="small mt-1">
                                        Works like Excel cells. Click the first cell, paste from Excel/Google Sheets, then import.
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 align-items-end mt-2">
                                        <div>
                                            <label class="form-label form-label-sm mb-1" for="nnPasteGridRows">Grid Rows</label>
                                            <input class="form-control form-control-sm" id="nnPasteGridRows" type="number" min="1" step="1" value="12">
                                        </div>
                                        <button class="btn btn-outline-secondary btn-sm" id="nnBuildPasteGridBtn" type="button">
                                            <i class="ri-layout-row-line me-1" aria-hidden="true"></i>
                                            Build Grid
                                        </button>
                                        <button class="btn btn-outline-primary btn-sm" id="nnImportGridBtn" type="button">
                                            <i class="ri-clipboard-line me-1" aria-hidden="true"></i>
                                            Import Grid
                                        </button>
                                        <button class="btn btn-outline-secondary btn-sm" id="nnClearGridBtn" type="button">
                                            <i class="ri-close-line me-1" aria-hidden="true"></i>
                                            Clear Grid
                                        </button>
                                    </div>
                                    <div class="form-text mt-2">
                                        Tip: paste starts from the active cell. Use <code>Ctrl/Cmd + Enter</code> to import quickly.
                                    </div>
                                    <div class="table-responsive mt-2 nn-paste-grid-wrap">
                                        <table class="table table-sm table-bordered mb-0 nn-paste-grid-table">
                                            <thead id="nnPasteGridHead"></thead>
                                            <tbody id="nnPasteGridBody"></tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="alert alert-light border mt-3 mb-0 nn-wizard-section d-none" role="alert" data-nn-wizard-section="settings">
                                    <div class="fw-semibold">CSV/XLSX Header Guide</div>
                                    <div class="small mt-1">
                                        Required: either <code>combo</code> or sequential columns <code>value_1</code> to <code>value_n</code> (<code>n = table by</code>).<br>
                                        Optional timestamp columns: <code>timestamp_ms</code>, <code>timestamp_iso</code>, <code>timestamp</code>, or <code>ts</code>. Extra columns are ignored.
                                    </div>
                                    <div class="small mt-2">
                                        Example header (by 6):<br>
                                        <code>timestamp_ms,value_1,value_2,value_3,value_4,value_5,value_6</code><br>
                                        or<br>
                                        <code>timestamp_iso,combo</code> (e.g. <code>2026-02-17 08:00:00,"1 4 7 9 12 18"</code>)
                                    </div>
                                </div>

                                <div class="alert alert-info mt-3 mb-0 nn-wizard-section d-none" role="alert" data-nn-wizard-section="settings">
                                    <div class="fw-semibold">Notes</div>
                                    <div class="small mt-1">
                                        1. Values are clamped to 0-55.<br>
                                        2. Range must cover at least 5 values.<br>
                                        3. Predictions use saved table history for the same by/min/max setup.<br>
                                        4. History is synced to database for your admin account.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card nn-card mt-3">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h4 class="header-title mb-0">Table Status</h4>
                                    <span class="text-muted small" id="nnStatusText">Ready</span>
                                </div>

                                <div class="row g-2 nn-stats">
                                    <div class="col-6">
                                        <div class="nn-statbox">
                                            <div class="k">History Entries</div>
                                            <div class="v" id="nnHistoryCount">0</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="nn-statbox">
                                            <div class="k">Rows Generated</div>
                                            <div class="v" id="nnRowsGenerated">0</div>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="nn-statbox">
                                            <div class="k">Active Table Key</div>
                                            <div class="v nn-key" id="nnTableKey">by6 | 0-55</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="nn-statbox">
                                            <div class="k">Algorithm</div>
                                            <div class="v" id="nnAlgoActive">XGBoost</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="nn-statbox">
                                            <div class="k">Stored Keys</div>
                                            <div class="v" id="nnStoredKeys">0</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8 nn-main-col">
                        <div class="card nn-card mb-3 nn-filter-card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <h4 class="header-title mb-0">Table Filter</h4>
                                    <div class="text-muted small" id="nnTableFilterMeta">Showing active setup table key.</div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label mb-1" for="nnTableFilterKey">Show Data For Table Key</label>
                                        <select class="form-select form-select-sm" id="nnTableFilterKey">
                                            <option value="">Active setup key</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1" for="nnTableFilterFromTs">From Timestamp</label>
                                        <input class="form-control form-control-sm" id="nnTableFilterFromTs" type="text" placeholder="e.g. 1 or 1700000000000">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1" for="nnTableFilterToTs">To Timestamp</label>
                                        <input class="form-control form-control-sm" id="nnTableFilterToTs" type="text" placeholder="e.g. 3357 or 1709999999999">
                                    </div>
                                    <div class="col-12 d-flex align-items-end gap-2">
                                        <button class="btn btn-sm btn-outline-primary" id="nnApplyTableFilterBtn" type="button">
                                            <i class="ri-filter-3-line me-1" aria-hidden="true"></i>
                                            Apply
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" id="nnResetTableFilterBtn" type="button">
                                            <i class="ri-refresh-line me-1" aria-hidden="true"></i>
                                            Active
                                        </button>
                                    </div>
                                </div>
                                <div class="form-text mt-2">
                                    Filters the tables and analysis by a stored key (<code>by + min + max</code>).
                                </div>
                            </div>
                        </div>

                        <div class="card nn-card mb-3 nn-actual-card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <h4 class="header-title mb-0">Actual Result Study</h4>
                                    <div class="text-muted small" id="nnActualStudyMeta">Add a manual result to evaluate all algorithms.</div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0 nn-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 160px;">Algorithm</th>
                                                <th>Predicted Combo</th>
                                                <th style="width: 110px;">Hits</th>
                                                <th style="width: 120px;">Distance</th>
                                                <th style="width: 120px;">Verdict</th>
                                            </tr>
                                        </thead>
                                        <tbody id="nnActualStudyBody">
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-4">No actual result study yet.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="card nn-card mb-3 nn-generated-card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <h4 class="header-title mb-0">Generated Table</h4>
                                    <div class="text-muted small" id="nnGeneratedMeta">No table generated yet.</div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0 nn-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 80px;">#</th>
                                                <th>Combination</th>
                                            </tr>
                                        </thead>
                                        <tbody id="nnGeneratedBody">
                                            <tr>
                                                <td colspan="2" class="text-center text-muted py-4">Generate a table to view combinations.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="card nn-card mb-3 nn-predict-card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <h4 class="header-title mb-0">Predictive Analysis (Selected Model)</h4>
                                    <div class="text-muted small" id="nnHitRate">Baseline hit rate: --</div>
                                </div>
                                <div class="text-muted small mb-2" id="nnModelMeta">Model: XGBoost</div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="nn-panel h-100">
                                            <div class="nn-panel-title">Top 5 Predictive Numbers</div>
                                            <div id="nnTopPredictions" class="nn-predict-list"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="nn-panel h-100">
                                            <div class="nn-panel-title">Predicted Next Combination</div>
                                            <div id="nnNextCombo" class="nn-next-combo">--</div>
                                            <div class="nn-note mt-2" id="nnPredictNote">Prediction updates after generation, manual input, or manual predict.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card nn-card mb-3 nn-compare-card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <h4 class="header-title mb-0">Algorithm Live Comparison</h4>
                                    <div class="text-muted small" id="nnCompareMeta">All algorithms compared using current key history.</div>
                                </div>

                                <div class="nn-compare-hit mb-3" id="nnCompareHitChart"></div>

                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0 nn-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 160px;">Algorithm</th>
                                                <th style="width: 120px;">Hit Rate</th>
                                                <th>Predicted Combo</th>
                                                <th>Top 5</th>
                                                <th>Formula</th>
                                            </tr>
                                        </thead>
                                        <tbody id="nnCompareBody">
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-4">Generate history to compare algorithms.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="card nn-card mb-3 nn-model-lab-card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <h4 class="header-title mb-0">Model Visual Lab</h4>
                                    <div class="text-muted small" id="nnModelLabMeta">Architecture visuals, sub-model accuracy, and predicted combinations.</div>
                                </div>
                                <div class="nn-model-grid" id="nnModelLabGrid">
                                    <div class="text-muted py-3">Generate history to build the model visual lab.</div>
                                </div>
                            </div>
                        </div>

                        <div class="card nn-card nn-history-card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <h4 class="header-title mb-0">Data Table (All Records, Latest First)</h4>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <label class="small text-muted mb-0" for="nnHistoryPageSize">Page Size</label>
                                        <select class="form-select form-select-sm" id="nnHistoryPageSize" style="width:auto;">
                                            <option value="25">25</option>
                                            <option value="50">50</option>
                                            <option value="100" selected>100</option>
                                            <option value="250">250</option>
                                            <option value="500">500</option>
                                            <option value="1000">1000</option>
                                            <option value="all">All</option>
                                        </select>
                                        <button class="btn btn-sm btn-outline-secondary" id="nnHistoryPrevBtn" type="button">Prev</button>
                                        <button class="btn btn-sm btn-outline-secondary" id="nnHistoryNextBtn" type="button">Next</button>
                                        <span class="small text-muted" id="nnHistoryPageInfo">Page 1 / 1</span>
                                    </div>
                                </div>
                                <div class="text-muted small mb-2" id="nnHistoryMeta">No history yet.</div>

                                <div class="table-responsive">
                                    <table class="table table-sm table-striped align-middle mb-0 nn-table">
                                        <thead id="nnHistoryHead">
                                            <tr>
                                                <th style="width: 80px;">#</th>
                                                <th>Value 1</th>
                                                <th>Value 2</th>
                                                <th>Value 3</th>
                                                <th style="width: 190px;">Generated At</th>
                                            </tr>
                                        </thead>
                                        <tbody id="nnHistoryBody">
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-4">No history for this table key yet.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php include '../layouts/footer.php'; ?>
    </div>
</div>

<?php include '../layouts/right-sidebar.php'; ?>
<?php include '../layouts/footer-scripts.php'; ?>
<script src="assets/js/app.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<div class="modal fade" id="nnRecalcModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                    <div>
                        <div class="fw-semibold" id="nnRecalcTitle">Recalculating All Algorithms</div>
                        <div class="text-muted small" id="nnRecalcText">Please wait while model outputs are updated.</div>
                    </div>
                </div>
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="nnRecalcProgressBar" role="progressbar" style="width: 0%;"></div>
                </div>
                <div class="text-muted small mt-2" id="nnRecalcProgressPct">0%</div>
            </div>
        </div>
    </div>
</div>
<script>
window.NN_API = <?php echo json_encode([
    'url' => $nnApiUrl,
    'csrfToken' => csrf_token(),
    'isSuperadmin' => $nnIsSuperadmin ? 1 : 0,
], JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/js/neighbor-numbers.js?v=<?php echo urlencode($nnJsVer); ?>"></script>
</body>
</html>

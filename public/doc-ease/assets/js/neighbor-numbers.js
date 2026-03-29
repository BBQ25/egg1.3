(function () {
  "use strict";

  var STORAGE_KEY = "nn_table_history_v2";
  var MAX_HISTORY_PER_KEY = 50000;
  var DEFAULT_ALGO = "xgboost";
  var DEFAULT_STYLE = "hybrid";
  var TABLE_SIZE_MIN = 2;
  var TABLE_SIZE_MAX = 20;
  var DEFAULT_ALLOWED_TABLE_SIZES = [3, 4, 5, 6, 7];
  var COMPARE_ALGOS = ["random_forest", "xgboost", "neural_network", "linear", "knn", "naive_bayes", "sma", "sgma"];
  var ALGO_COLORS = {
    random_forest: "linear-gradient(90deg, #0ea5e9, #22d3ee)",
    xgboost: "linear-gradient(90deg, #2563eb, #14b8a6)",
    neural_network: "linear-gradient(90deg, #f97316, #f59e0b)",
    linear: "linear-gradient(90deg, #8b5cf6, #6366f1)",
    knn: "linear-gradient(90deg, #ef4444, #f97316)",
    naive_bayes: "linear-gradient(90deg, #0f766e, #14b8a6)",
    sma: "linear-gradient(90deg, #475569, #0ea5e9)",
    sgma: "linear-gradient(90deg, #1d4ed8, #0f766e)"
  };
  var ALGO_META = {
    random_forest: {
      label: "Random Forest",
      detail: "tree-vote ensemble over frequency, recency, transition, and overdue signals"
    },
    xgboost: {
      label: "XGBoost",
      detail: "boosted additive scorer tuned to residual historical patterns"
    },
    neural_network: {
      label: "Neural Networks",
      detail: "non-linear weighted scorer over trend and recency signals"
    },
    linear: {
      label: "Linear",
      detail: "weighted linear blend of frequency and recency features"
    },
    knn: {
      label: "KNN",
      detail: "nearest-history voting from combinations similar to the latest pattern"
    },
    naive_bayes: {
      label: "Naive Bayes",
      detail: "independence-based posterior ranking using normalized signal probabilities"
    },
    sma: {
      label: "SMA",
      detail: "simple moving-average frequency trend over recent windows"
    },
    sgma: {
      label: "SGMA",
      detail: "stability-gated moving average that downweights volatile trend spikes"
    }
  };
  var MODEL_VISUAL_GROUPS = [
    {
      id: "random_forest",
      title: "Random Forest",
      subtitle: "Bagging-based ensemble classifier visual",
      visual: "random_forest",
      variants: [
        { id: "rf_bagging", label: "Bagging Trees", algo: "random_forest", delta: { freqMult: 1.05, recencyMult: 1.05, transitionMult: 1.06 }, formula: "Vote(T1..Tn) with bootstrap bags" },
        { id: "rf_extra", label: "Extra Trees Mix", algo: "random_forest", delta: { baseMix: -0.03, coMult: 1.10, transitionMult: 1.14, overdueMult: 1.04 }, formula: "Random split emphasis + variance reduction" }
      ]
    },
    {
      id: "xgboost",
      title: "XGBoost",
      subtitle: "Residual boosting and weighted summation visual",
      visual: "xgboost",
      variants: [
        { id: "xgb_gbtree", label: "GBTree", algo: "xgboost", delta: { recencyMult: 1.10, transitionMult: 1.08, overdueMult: 0.95 }, formula: "F(x)=sum(w_t * tree_t(x))" },
        { id: "xgb_dart", label: "DART-style", algo: "xgboost", delta: { baseMix: -0.04, coMult: 1.12, transitionMult: 1.10, overdueMult: 1.08 }, formula: "Dropout-boosted additive trees" }
      ]
    },
    {
      id: "neural_network",
      title: "Neural Networks",
      subtitle: "Multi-layer and sequence-aware neural sub-models",
      visual: "neural_network",
      variants: [
        { id: "nn_mlp", label: "MLP", algo: "neural_network", delta: { freqMult: 1.02, recencyMult: 1.08, coMult: 1.06 }, formula: "Dense layers + non-linear activation" },
        { id: "nn_cnn", label: "CNN", algo: "neural_network", delta: { recentMult: 1.16, transitionMult: 1.10, coMult: 1.08 }, formula: "Local pattern filters + pooling" },
        { id: "nn_lstm", label: "LSTM/RNN", algo: "neural_network", delta: { recencyMult: 1.18, transitionMult: 1.20, recentMult: 1.15 }, formula: "Temporal gates over sequence history" }
      ]
    },
    {
      id: "linear",
      title: "Linear Regression Family",
      subtitle: "Regularized linear sub-regressions with accuracy snapshots",
      visual: "linear",
      variants: [
        { id: "lin_ols", label: "OLS", algo: "linear", delta: { baseMix: 0.02, freqMult: 1.08, recencyMult: 1.05 }, formula: "y = b0 + b1*F + b2*R + ..." },
        { id: "lin_ridge", label: "Ridge", algo: "linear", delta: { baseMix: 0.04, freqMult: 1.12, overdueMult: 0.90 }, formula: "OLS + L2 penalty" },
        { id: "lin_lasso", label: "Lasso", algo: "linear", delta: { baseMix: -0.01, coMult: 1.12, transitionMult: 1.05 }, formula: "OLS + L1 sparsity" },
        { id: "lin_elastic", label: "Elastic Net", algo: "linear", delta: { freqMult: 1.06, recencyMult: 1.06, coMult: 1.06 }, formula: "Hybrid L1/L2 regularization" }
      ]
    },
    {
      id: "knn",
      title: "K-Nearest Neighbors",
      subtitle: "Distance-based neighborhood voting variants",
      visual: "knn",
      variants: [
        { id: "knn_euclidean", label: "Euclidean Distance", algo: "knn", delta: { transitionMult: 1.20, recencyMult: 1.08 }, formula: "d = sqrt(sum((x_i-y_i)^2))" },
        { id: "knn_manhattan", label: "Manhattan Distance", algo: "knn", delta: { baseMix: 0.02, freqMult: 1.10, transitionMult: 1.06 }, formula: "d = sum(|x_i-y_i|)" }
      ]
    },
    {
      id: "naive_bayes",
      title: "Naive Bayes",
      subtitle: "Posterior probability variants under independence assumptions",
      visual: "naive_bayes",
      variants: [
        { id: "nb_gaussian", label: "Gaussian NB", algo: "naive_bayes", delta: { recencyMult: 1.06, overdueMult: 0.94 }, formula: "P(C|X) ~ P(C)*prod P(x_i|C)" },
        { id: "nb_multinomial", label: "Multinomial NB", algo: "naive_bayes", delta: { freqMult: 1.16, coMult: 0.94 }, formula: "Count-based likelihoods per class" },
        { id: "nb_bernoulli", label: "Bernoulli NB", algo: "naive_bayes", delta: { recentMult: 1.12, coMult: 1.10 }, formula: "Binary feature presence likelihoods" }
      ]
    },
    {
      id: "sma",
      title: "Simple Moving Average",
      subtitle: "Windowed trend-following without volatility gating",
      visual: "sma",
      variants: [
        { id: "sma_w8", label: "SMA (Window 8)", algo: "sma", delta: { recentMult: 1.16, recencyMult: 1.10 }, formula: "SMA_t = avg(last 8 windows)" },
        { id: "sma_w16", label: "SMA (Window 16)", algo: "sma", delta: { baseMix: 0.03, freqMult: 1.08, recentMult: 1.04 }, formula: "SMA_t = avg(last 16 windows)" }
      ]
    },
    {
      id: "sgma",
      title: "Stability-gated MA",
      subtitle: "Moving average with volatility gate for safer picks",
      visual: "sgma",
      variants: [
        { id: "sgma_bal", label: "SGMA Balanced", algo: "sgma", delta: { recencyMult: 1.10, transitionMult: 1.08, overdueMult: 0.94 }, formula: "SGMA = SMA * stability_gate" },
        { id: "sgma_strict", label: "SGMA Strict", algo: "sgma", delta: { baseMix: 0.06, freqMult: 1.12, transitionMult: 0.92, overdueMult: 0.86 }, formula: "Higher gate threshold, lower volatility" }
      ]
    }
  ];
  var STYLE_META = {
    hybrid: {
      label: "Hybrid",
      detail: "stable picks with controlled trend chase"
    },
    balanced: {
      label: "Balanced",
      detail: "best overall hit rate"
    },
    conservative: {
      label: "Conservative",
      detail: "stable picks with lower volatility"
    },
    momentum: {
      label: "Momentum",
      detail: "leans on recent and transition patterns"
    },
    exploratory: {
      label: "Exploratory",
      detail: "more diverse top-5 number exploration"
    }
  };
  var API_CFG = (window.NN_API && typeof window.NN_API === "object") ? window.NN_API : {};
  var STORE_API_URL = String(API_CFG.url || "").trim();
  var STORE_API_CSRF = String(API_CFG.csrfToken || "").trim();
  var IS_SUPERADMIN = Number(API_CFG.isSuperadmin || 0) === 1;

  var els = {
    tableSize: document.getElementById("nnTableSize"),
    repeatRuleHint: document.getElementById("nnRepeatRuleHint"),
    tableSupportText: document.getElementById("nnTableSupportText"),
    tableSizesPill: document.getElementById("nnTableSizesPill"),
    wizardSetupBtn: document.getElementById("nnWizardSetupBtn"),
    wizardSettingsBtn: document.getElementById("nnWizardSettingsBtn"),
    wizardMeta: document.getElementById("nnWizardMeta"),
    wizardTableList: document.getElementById("nnWizardTableList"),
    wizardTableMeta: document.getElementById("nnWizardTableMeta"),
    min: document.getElementById("nnMin"),
    max: document.getElementById("nnMax"),
    algorithm: document.getElementById("nnAlgorithm"),
    accuracyStyle: document.getElementById("nnAccuracyStyle"),
    allowedSizesInput: document.getElementById("nnAllowedSizesInput"),
    repeatSizeChecks: document.getElementById("nnRepeatSizeChecks"),
    savePolicyBtn: document.getElementById("nnSavePolicyBtn"),
    policyMeta: document.getElementById("nnPolicyMeta"),
    policyReadOnlyMeta: document.getElementById("nnPolicyReadOnlyMeta"),
    generateBtn: document.getElementById("nnGenerateBtn"),
    predictBtn: document.getElementById("nnPredictBtn"),
    clearHistoryBtn: document.getElementById("nnClearHistoryBtn"),
    exportJsonBtn: document.getElementById("nnExportJsonBtn"),
    exportCsvBtn: document.getElementById("nnExportCsvBtn"),
    importBtn: document.getElementById("nnImportBtn"),
    importFile: document.getElementById("nnImportFile"),
    manualCombo: document.getElementById("nnManualCombo"),
    manualHint: document.getElementById("nnManualHint"),
    addManualBtn: document.getElementById("nnAddManualBtn"),
    clearManualBtn: document.getElementById("nnClearManualBtn"),
    exportAllJsonBtn: document.getElementById("nnExportAllJsonBtn"),
    importAllJsonBtn: document.getElementById("nnImportAllJsonBtn"),
    importAllFile: document.getElementById("nnImportAllFile"),
    pasteGridRows: document.getElementById("nnPasteGridRows"),
    buildPasteGridBtn: document.getElementById("nnBuildPasteGridBtn"),
    importGridBtn: document.getElementById("nnImportGridBtn"),
    clearGridBtn: document.getElementById("nnClearGridBtn"),
    pasteGridHead: document.getElementById("nnPasteGridHead"),
    pasteGridBody: document.getElementById("nnPasteGridBody"),

    statusText: document.getElementById("nnStatusText"),
    historyCount: document.getElementById("nnHistoryCount"),
    rowsGenerated: document.getElementById("nnRowsGenerated"),
    tableKey: document.getElementById("nnTableKey"),
    tableFilterKey: document.getElementById("nnTableFilterKey"),
    tableFilterFromTs: document.getElementById("nnTableFilterFromTs"),
    tableFilterToTs: document.getElementById("nnTableFilterToTs"),
    applyTableFilterBtn: document.getElementById("nnApplyTableFilterBtn"),
    resetTableFilterBtn: document.getElementById("nnResetTableFilterBtn"),
    tableFilterMeta: document.getElementById("nnTableFilterMeta"),
    algoActive: document.getElementById("nnAlgoActive"),
    storedKeys: document.getElementById("nnStoredKeys"),

    generatedMeta: document.getElementById("nnGeneratedMeta"),
    generatedBody: document.getElementById("nnGeneratedBody"),

    topPredictions: document.getElementById("nnTopPredictions"),
    nextCombo: document.getElementById("nnNextCombo"),
    hitRate: document.getElementById("nnHitRate"),
    modelMeta: document.getElementById("nnModelMeta"),
    predictNote: document.getElementById("nnPredictNote"),
    compareMeta: document.getElementById("nnCompareMeta"),
    compareHitChart: document.getElementById("nnCompareHitChart"),
    compareBody: document.getElementById("nnCompareBody"),
    modelLabMeta: document.getElementById("nnModelLabMeta"),
    modelLabGrid: document.getElementById("nnModelLabGrid"),
    actualStudyMeta: document.getElementById("nnActualStudyMeta"),
    actualStudyBody: document.getElementById("nnActualStudyBody"),

    historyMeta: document.getElementById("nnHistoryMeta"),
    historyHead: document.getElementById("nnHistoryHead"),
    historyBody: document.getElementById("nnHistoryBody"),
    historyPageSize: document.getElementById("nnHistoryPageSize"),
    historyPrevBtn: document.getElementById("nnHistoryPrevBtn"),
    historyNextBtn: document.getElementById("nnHistoryNextBtn"),
    historyPageInfo: document.getElementById("nnHistoryPageInfo"),

    recalcModal: document.getElementById("nnRecalcModal"),
    recalcTitle: document.getElementById("nnRecalcTitle"),
    recalcText: document.getElementById("nnRecalcText"),
    recalcProgressBar: document.getElementById("nnRecalcProgressBar"),
    recalcProgressPct: document.getElementById("nnRecalcProgressPct")
  };

  if (!els.tableSize || !els.generateBtn || !els.generatedBody || !els.topPredictions) return;

  var state = {
    currentRows: [],
    currentConfigKey: "",
    generatedAt: 0,
    tuningCache: Object.create(null),
    storeCache: null,
    remoteSyncTimer: 0,
    remoteSyncInFlight: false,
    remoteSyncQueued: false,
    remoteSyncWarned: false,
    remoteBootstrapped: false,
    manualDraft: null,
    modelVariantCache: Object.create(null),
    settings: {
      allowedTableSizes: DEFAULT_ALLOWED_TABLE_SIZES.slice(),
      repeatableSizes: []
    },
    algoSnapshots: Object.create(null),
    recalcModal: null,
    pasteGridMeta: null,
    pasteGridColumns: [],
    wizardStep: "setup",
    tableFilterStoreKey: "",
    tableProfiles: Object.create(null),
    activeWizardBy: 0,
    historyPage: 1,
    historyPageSize: 100,
    historyRangeFromTs: null,
    historyRangeToTs: null,
    lastActualStudy: null
  };

  function normalizeAlgorithm(raw) {
    var algo = String(raw == null ? "" : raw).trim().toLowerCase();
    if (algo === "random_forest") return "random_forest";
    if (algo === "xgboost") return "xgboost";
    if (algo === "neural_network") return "neural_network";
    if (algo === "linear") return "linear";
    if (algo === "knn") return "knn";
    if (algo === "naive_bayes") return "naive_bayes";
    if (algo === "sma") return "sma";
    if (algo === "sgma") return "sgma";
    return DEFAULT_ALGO;
  }

  function algorithmLabel(algo) {
    var key = normalizeAlgorithm(algo);
    return ALGO_META[key] ? ALGO_META[key].label : ALGO_META[DEFAULT_ALGO].label;
  }

  function algorithmDetail(algo) {
    var key = normalizeAlgorithm(algo);
    return ALGO_META[key] ? ALGO_META[key].detail : ALGO_META[DEFAULT_ALGO].detail;
  }

  function normalizeAccuracyStyle(raw) {
    var style = String(raw == null ? "" : raw).trim().toLowerCase();
    if (style === "hybrid") return "hybrid";
    if (style === "balanced") return "balanced";
    if (style === "conservative") return "conservative";
    if (style === "momentum") return "momentum";
    if (style === "exploratory") return "exploratory";
    return DEFAULT_STYLE;
  }

  function accuracyStyleLabel(style) {
    var key = normalizeAccuracyStyle(style);
    return STYLE_META[key] ? STYLE_META[key].label : STYLE_META[DEFAULT_STYLE].label;
  }

  function accuracyStyleDetail(style) {
    var key = normalizeAccuracyStyle(style);
    return STYLE_META[key] ? STYLE_META[key].detail : STYLE_META[DEFAULT_STYLE].detail;
  }

  function normalizeTableSizeValue(raw) {
    var n = parseInt(String(raw == null ? "" : raw), 10);
    if (!isFinite(n)) return null;
    if (n < TABLE_SIZE_MIN || n > TABLE_SIZE_MAX) return null;
    return n;
  }

  function normalizeSizeList(raw) {
    var source = [];
    if (Array.isArray(raw)) {
      source = raw.slice();
    } else {
      source = String(raw == null ? "" : raw).split(/[^0-9]+/);
    }
    var set = Object.create(null);
    for (var i = 0; i < source.length; i++) {
      var v = normalizeTableSizeValue(source[i]);
      if (v == null) continue;
      set[String(v)] = true;
    }
    var out = Object.keys(set).map(function (k) { return parseInt(k, 10); });
    out.sort(function (a, b) { return a - b; });
    return out;
  }

  function defaultSettingsObject() {
    return {
      allowedTableSizes: DEFAULT_ALLOWED_TABLE_SIZES.slice(),
      repeatableSizes: []
    };
  }

  function sanitizeSettingsObject(raw) {
    var source = (raw && typeof raw === "object") ? raw : {};
    var allowed = normalizeSizeList(source.allowed_table_sizes || source.allowedTableSizes || []);
    if (allowed.length < 1) allowed = DEFAULT_ALLOWED_TABLE_SIZES.slice();

    var allowedMap = Object.create(null);
    for (var i = 0; i < allowed.length; i++) allowedMap[String(allowed[i])] = true;

    var repeat = normalizeSizeList(source.repeatable_sizes || source.repeatableSizes || []);
    var repeatOut = [];
    for (var r = 0; r < repeat.length; r++) {
      if (!allowedMap[String(repeat[r])]) continue;
      repeatOut.push(repeat[r]);
    }
    repeatOut.sort(function (a, b) { return a - b; });

    return {
      allowedTableSizes: allowed,
      repeatableSizes: repeatOut
    };
  }

  function activeAllowedSizes() {
    var sizes = (state.settings && Array.isArray(state.settings.allowedTableSizes))
      ? state.settings.allowedTableSizes.slice()
      : DEFAULT_ALLOWED_TABLE_SIZES.slice();
    if (sizes.length < 1) sizes = DEFAULT_ALLOWED_TABLE_SIZES.slice();
    sizes.sort(function (a, b) { return a - b; });
    return sizes;
  }

  function isRepeatAllowedForSize(by) {
    var size = parseInt(by, 10);
    if (!isFinite(size)) return false;
    var rep = (state.settings && Array.isArray(state.settings.repeatableSizes))
      ? state.settings.repeatableSizes
      : [];
    for (var i = 0; i < rep.length; i++) {
      if (rep[i] === size) return true;
    }
    return false;
  }

  function ensureTableSizeSelectOptions(preferredBy) {
    if (!els.tableSize) return;
    var sizes = activeAllowedSizes();
    var selected = normalizeTableSizeValue(preferredBy);
    if (selected == null || sizes.indexOf(selected) < 0) {
      selected = sizes.indexOf(6) >= 0 ? 6 : sizes[0];
    }

    var html = [];
    for (var i = 0; i < sizes.length; i++) {
      html.push('<option value="' + sizes[i] + '">' + sizes[i] + "</option>");
    }
    els.tableSize.innerHTML = html.join("");
    els.tableSize.value = String(selected);
  }

  function tableSizesSummaryText() {
    var sizes = activeAllowedSizes();
    if (sizes.length < 1) return "by 3-7";
    return "by " + sizes.join(", ");
  }

  function refreshTablePolicyLabels() {
    var summary = tableSizesSummaryText();
    if (els.tableSupportText) {
      els.tableSupportText.textContent = "Configured table sizes: " + summary + ". Value range is limited to 0 to 55.";
    }
    if (els.tableSizesPill) {
      els.tableSizesPill.innerHTML = '<i class="ri-table-line" aria-hidden="true"></i> ' + escapeHtml(summary);
    }
  }

  function refreshRepeatRuleHint(by) {
    if (!els.repeatRuleHint) return;
    var size = parseInt(by, 10);
    if (!isFinite(size)) size = 0;
    if (isRepeatAllowedForSize(size)) {
      els.repeatRuleHint.textContent = "Repeated numbers are allowed for table by " + size + ".";
      return;
    }
    els.repeatRuleHint.textContent = "No repeated numbers for table by " + size + ".";
  }

  function renderRepeatSizeCheckboxes(allowedSizes, repeatSizes) {
    if (!els.repeatSizeChecks) return;
    var allowed = Array.isArray(allowedSizes) ? allowedSizes.slice() : [];
    var repeatMap = Object.create(null);
    var repeats = Array.isArray(repeatSizes) ? repeatSizes : [];
    for (var r = 0; r < repeats.length; r++) {
      repeatMap[String(repeats[r])] = true;
    }

    var checkHtml = [];
    for (var i = 0; i < allowed.length; i++) {
      var size = allowed[i];
      var id = "nnRepeatSize_" + size;
      var checked = repeatMap[String(size)] ? ' checked' : "";
      checkHtml.push(
        '<div class="form-check form-check-inline m-0">' +
          '<input class="form-check-input" type="checkbox" id="' + id + '" value="' + size + '"' + checked + ">" +
          '<label class="form-check-label small" for="' + id + '">by ' + size + "</label>" +
        "</div>"
      );
    }
    els.repeatSizeChecks.innerHTML = checkHtml.join("");
  }

  function renderPolicyControls() {
    refreshTablePolicyLabels();

    var currentBy = normalizeTableSizeValue(els.tableSize ? els.tableSize.value : null);
    ensureTableSizeSelectOptions(currentBy);
    refreshRepeatRuleHint(els.tableSize ? els.tableSize.value : null);
    renderTableWizardButtons(normalizeTableSizeValue(els.tableSize ? els.tableSize.value : null));

    if (els.policyReadOnlyMeta) {
      var roText = "Managed by superadmin. Active sizes: " + activeAllowedSizes().join(", ");
      els.policyReadOnlyMeta.textContent = roText;
    }

    if (!IS_SUPERADMIN) return;
    if (!els.allowedSizesInput || !els.repeatSizeChecks) return;

    var allowed = activeAllowedSizes();
    var repeats = state.settings && Array.isArray(state.settings.repeatableSizes) ? state.settings.repeatableSizes : [];

    els.allowedSizesInput.value = allowed.join(",");
    renderRepeatSizeCheckboxes(allowed, repeats);

    if (els.policyMeta) {
      els.policyMeta.textContent = "Loaded policy: sizes " + allowed.join(", ") + ".";
    }
  }

  function profileKeyForBy(by) {
    return "by" + String(parseInt(by, 10));
  }

  function defaultTableProfile(by) {
    var size = parseInt(by, 10);
    if (!isFinite(size) || size < TABLE_SIZE_MIN || size > TABLE_SIZE_MAX) size = 3;
    return {
      by: size,
      min: 0,
      max: 55,
      algorithm: DEFAULT_ALGO,
      accuracyStyle: DEFAULT_STYLE,
      rangeFromTs: null,
      rangeToTs: null,
      pageSize: 100,
      filterStoreKey: ""
    };
  }

  function getTableProfile(by) {
    var key = profileKeyForBy(by);
    var existing = state.tableProfiles[key];
    if (existing && typeof existing === "object") return existing;
    var created = defaultTableProfile(by);
    state.tableProfiles[key] = created;
    return created;
  }

  function rememberProfileFromConfig(cfg) {
    if (!cfg) return;
    var profile = getTableProfile(cfg.by);
    profile.by = cfg.by;
    profile.min = cfg.min;
    profile.max = cfg.max;
    profile.algorithm = normalizeAlgorithm(cfg.algorithm);
    profile.accuracyStyle = normalizeAccuracyStyle(cfg.accuracyStyle);
    profile.rangeFromTs = state.historyRangeFromTs;
    profile.rangeToTs = state.historyRangeToTs;
    profile.pageSize = state.historyPageSize > 0 ? state.historyPageSize : 0;
    profile.filterStoreKey = state.tableFilterStoreKey || "";
    state.activeWizardBy = cfg.by;
  }

  function applyProfileToInputs(by) {
    var size = parseInt(by, 10);
    if (!isFinite(size)) return;
    var profile = getTableProfile(size);
    if (els.tableSize) els.tableSize.value = String(size);
    if (els.min) els.min.value = String(clampInt(profile.min, 0, 55, 0));
    if (els.max) els.max.value = String(clampInt(profile.max, 0, 55, 55));
    if (els.algorithm) els.algorithm.value = normalizeAlgorithm(profile.algorithm);
    if (els.accuracyStyle) els.accuracyStyle.value = normalizeAccuracyStyle(profile.accuracyStyle);
    state.historyRangeFromTs = profile.rangeFromTs;
    state.historyRangeToTs = profile.rangeToTs;
    state.historyPageSize = normalizeHistoryPageSize(profile.pageSize, 100);
    if (els.historyPageSize) {
      els.historyPageSize.value = state.historyPageSize === 0 ? "all" : String(state.historyPageSize);
    }
    state.tableFilterStoreKey = String(profile.filterStoreKey || "");
    state.historyPage = 1;
    state.activeWizardBy = size;
  }

  function renderTableWizardButtons(activeBy) {
    if (!els.wizardTableList) return;
    var allowed = activeAllowedSizes();
    var activeSize = parseInt(activeBy, 10);
    if (!isFinite(activeSize) || allowed.indexOf(activeSize) < 0) {
      activeSize = allowed.indexOf(6) >= 0 ? 6 : allowed[0];
    }

    var html = [];
    for (var i = 0; i < allowed.length; i++) {
      var size = allowed[i];
      var activeClass = size === activeSize ? "btn-primary" : "btn-outline-primary";
      html.push(
        '<button type="button" class="btn btn-sm ' + activeClass + ' nn-wizard-table-btn" data-nn-wizard-by="' + size + '">' +
          'Table ' + size +
        "</button>"
      );
    }
    els.wizardTableList.innerHTML = html.join("");

    var btns = els.wizardTableList.querySelectorAll("button.nn-wizard-table-btn");
    for (var b = 0; b < btns.length; b++) {
      btns[b].addEventListener("click", function (ev) {
        var rawBy = ev && ev.currentTarget ? ev.currentTarget.getAttribute("data-nn-wizard-by") : "";
        var byN = parseInt(String(rawBy || ""), 10);
        if (!isFinite(byN)) return;
        onWizardTableSelect(byN);
      });
    }

    if (els.wizardTableMeta) {
      var prof = getTableProfile(activeSize);
      els.wizardTableMeta.textContent =
        "Active wizard: table " + activeSize +
        " | range " + prof.min + "-" + prof.max +
        " | algo " + algorithmLabel(prof.algorithm) +
        " | style " + accuracyStyleLabel(prof.accuracyStyle);
    }
  }

  function onWizardTableSelect(by) {
    var norm = normalizeConfig();
    rememberProfileFromConfig(finalizeConfig(norm.cfg));
    applyProfileToInputs(by);
    var nextNorm = normalizeConfig();
    var cfg = finalizeConfig(nextNorm.cfg);
    rememberProfileFromConfig(cfg);
    renderTableWizardButtons(cfg.by);
    renderForConfig(cfg);
    setStatus("Switched to table wizard " + cfg.by);
  }

  function setWizardStep(step) {
    var target = step === "settings" ? "settings" : "setup";
    state.wizardStep = target;

    var sections = document.querySelectorAll("[data-nn-wizard-section]");
    for (var i = 0; i < sections.length; i++) {
      var section = sections[i];
      var sectionKey = String(section.getAttribute("data-nn-wizard-section") || "").trim().toLowerCase();
      var isVisible = sectionKey === target;
      section.classList.toggle("d-none", !isVisible);
    }

    if (els.wizardSetupBtn) {
      els.wizardSetupBtn.classList.toggle("btn-primary", target === "setup");
      els.wizardSetupBtn.classList.toggle("btn-outline-primary", target !== "setup");
      els.wizardSetupBtn.setAttribute("aria-pressed", target === "setup" ? "true" : "false");
    }
    if (els.wizardSettingsBtn) {
      els.wizardSettingsBtn.classList.toggle("btn-primary", target === "settings");
      els.wizardSettingsBtn.classList.toggle("btn-outline-primary", target !== "settings");
      els.wizardSettingsBtn.setAttribute("aria-pressed", target === "settings" ? "true" : "false");
    }
    if (els.wizardMeta) {
      els.wizardMeta.textContent = target === "settings"
        ? "Settings step: policy, import/export, and spreadsheet tools."
        : "Setup step: configure table key and run analysis.";
    }
  }

  function collectStoredKeySummaries() {
    var store = sanitizeStoreObject(loadStore()).store;
    var keys = Object.keys(store);
    var out = [];
    for (var i = 0; i < keys.length; i++) {
      var storeKey = keys[i];
      var parsed = parseStoreKey(storeKey);
      if (!parsed) continue;
      var rows = Array.isArray(store[storeKey]) ? store[storeKey].length : 0;
      out.push({
        storeKey: parsed.storeKey,
        key: "by" + parsed.by + " | " + parsed.min + "-" + parsed.max,
        by: parsed.by,
        min: parsed.min,
        max: parsed.max,
        count: rows
      });
    }
    out.sort(function (a, b) {
      if (b.count !== a.count) return b.count - a.count;
      if (a.by !== b.by) return a.by - b.by;
      if (a.min !== b.min) return a.min - b.min;
      return a.max - b.max;
    });
    return out;
  }

  function setInputsFromConfigLike(by, minV, maxV) {
    if (els.tableSize) els.tableSize.value = String(by);
    if (els.min) els.min.value = String(minV);
    if (els.max) els.max.value = String(maxV);
  }

  function maybeAutoSelectKeyWithHistory(baseCfg) {
    var cfg = baseCfg && typeof baseCfg === "object" ? baseCfg : null;
    if (!cfg) return cfg;

    var currentCount = getHistory(cfg).length;
    if (currentCount > 0) return cfg;

    var summaries = collectStoredKeySummaries();
    if (!Array.isArray(summaries) || summaries.length < 1) return cfg;
    var top = summaries[0];
    if (!top || top.count < 1) return cfg;

    setInputsFromConfigLike(top.by, top.min, top.max);
    var norm = normalizeConfig();
    var next = finalizeConfig(norm.cfg);
    state.tableFilterStoreKey = next.storeKey;
    rememberProfileFromConfig(next);
    renderTableWizardButtons(next.by);
    return next;
  }

  function refreshTableFilterOptions(activeCfg) {
    if (!els.tableFilterKey) return;

    var cfg = activeCfg && typeof activeCfg === "object" ? activeCfg : null;
    var summaries = collectStoredKeySummaries();
    var summaryMap = Object.create(null);
    for (var i = 0; i < summaries.length; i++) {
      summaryMap[summaries[i].storeKey] = summaries[i];
    }

    var activeLabel = cfg ? cfg.key : "active setup key";
    var html = ['<option value="">Active setup key (' + escapeHtml(activeLabel) + ")</option>"];
    for (var s = 0; s < summaries.length; s++) {
      var item = summaries[s];
      html.push(
        '<option value="' + escapeHtml(item.storeKey) + '">' +
        escapeHtml(item.key + " (" + item.count + " entries)") +
        "</option>"
      );
    }
    els.tableFilterKey.innerHTML = html.join("");

    var preferred = String(state.tableFilterStoreKey || "");
    if (preferred === "" && cfg && cfg.storeKey) preferred = String(cfg.storeKey);
    if (preferred !== "" && summaryMap[preferred]) {
      els.tableFilterKey.value = preferred;
      state.tableFilterStoreKey = preferred;
    } else {
      els.tableFilterKey.value = "";
      state.tableFilterStoreKey = "";
    }

    if (els.tableFilterFromTs) {
      els.tableFilterFromTs.value = state.historyRangeFromTs == null ? "" : String(state.historyRangeFromTs);
    }
    if (els.tableFilterToTs) {
      els.tableFilterToTs.value = state.historyRangeToTs == null ? "" : String(state.historyRangeToTs);
    }

    if (els.tableFilterMeta) {
      var selectedKey = String(els.tableFilterKey.value || "");
      var rangeText = "";
      if (state.historyRangeFromTs != null || state.historyRangeToTs != null) {
        var fromText = state.historyRangeFromTs == null ? "-inf" : String(state.historyRangeFromTs);
        var toText = state.historyRangeToTs == null ? "+inf" : String(state.historyRangeToTs);
        rangeText = " | range " + fromText + " to " + toText;
      }
      if (selectedKey !== "" && summaryMap[selectedKey]) {
        var selected = summaryMap[selectedKey];
        var activeTag = (cfg && selectedKey === cfg.storeKey) ? " | active" : "";
        els.tableFilterMeta.textContent = "Showing " + selected.key + " (" + selected.count + " entries)" + activeTag + rangeText + ".";
      } else {
        els.tableFilterMeta.textContent = "Showing active setup table key" + rangeText + ".";
      }
    }
  }

  function parseFilterRangeValue(raw) {
    var text = String(raw == null ? "" : raw).trim();
    if (text === "") return null;
    var n = parseInt(text, 10);
    if (isFinite(n)) return n;
    var d = Date.parse(text);
    if (isFinite(d)) return d;
    return NaN;
  }

  function normalizeRangeBounds(fromTs, toTs) {
    var fromVal = (typeof fromTs === "number" && isFinite(fromTs)) ? fromTs : null;
    var toVal = (typeof toTs === "number" && isFinite(toTs)) ? toTs : null;
    if (fromVal != null && toVal != null && fromVal > toVal) {
      var temp = fromVal;
      fromVal = toVal;
      toVal = temp;
    }
    return { fromTs: fromVal, toTs: toVal };
  }

  function normalizeHistoryPageSize(raw, fallback) {
    var text = String(raw == null ? "" : raw).trim().toLowerCase();
    if (text === "all" || text === "0") return 0;
    var n = parseInt(text, 10);
    if (!isFinite(n)) return fallback;
    if (n < 10) n = 10;
    if (n > 50000) n = 50000;
    return n;
  }

  function clampInt(v, min, max, fallback) {
    var n = parseInt(String(v == null ? "" : v), 10);
    if (!isFinite(n)) return fallback;
    if (n < min) return min;
    if (n > max) return max;
    return n;
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function setStatus(text) {
    if (!els.statusText) return;
    els.statusText.textContent = text || "Ready";
  }

  function formatDateTime(ts) {
    var d = new Date(ts);
    if (isNaN(d.getTime())) return "--";
    var y = d.getFullYear();
    var m = String(d.getMonth() + 1).padStart(2, "0");
    var day = String(d.getDate()).padStart(2, "0");
    var hh = String(d.getHours()).padStart(2, "0");
    var mm = String(d.getMinutes()).padStart(2, "0");
    var ss = String(d.getSeconds()).padStart(2, "0");
    return y + "-" + m + "-" + day + " " + hh + ":" + mm + ":" + ss;
  }

  function formatFileStamp(ts) {
    var d = new Date(ts);
    if (isNaN(d.getTime())) d = new Date();
    var y = d.getFullYear();
    var m = String(d.getMonth() + 1).padStart(2, "0");
    var day = String(d.getDate()).padStart(2, "0");
    var hh = String(d.getHours()).padStart(2, "0");
    var mm = String(d.getMinutes()).padStart(2, "0");
    var ss = String(d.getSeconds()).padStart(2, "0");
    return y + m + day + "_" + hh + mm + ss;
  }

  function parseTimestampLoose(raw, fallbackTs) {
    var n = parseInt(String(raw == null ? "" : raw), 10);
    if (isFinite(n) && n > 0) return n;
    var dt = Date.parse(String(raw == null ? "" : raw));
    if (isFinite(dt) && dt > 0) return dt;
    return fallbackTs;
  }

  function downloadTextFile(filename, mimeType, content) {
    try {
      var blob = new Blob([String(content == null ? "" : content)], { type: String(mimeType || "text/plain") });
      var url = URL.createObjectURL(blob);
      var a = document.createElement("a");
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      window.setTimeout(function () {
        try { URL.revokeObjectURL(url); } catch (e) {}
        try { a.remove(); } catch (e) {}
      }, 80);
    } catch (e) {
      window.alert("Unable to download file in this browser.");
    }
  }

  function csvEscape(value) {
    var s = String(value == null ? "" : value);
    if (/[\",\\n\\r]/.test(s)) {
      s = "\"" + s.replace(/\"/g, "\"\"") + "\"";
    }
    return s;
  }

  function parseCsvText(text) {
    var src = String(text == null ? "" : text);
    var rows = [];
    var row = [];
    var field = "";
    var inQuotes = false;

    for (var i = 0; i < src.length; i++) {
      var ch = src.charAt(i);
      var next = src.charAt(i + 1);

      if (inQuotes) {
        if (ch === "\"") {
          if (next === "\"") {
            field += "\"";
            i++;
          } else {
            inQuotes = false;
          }
        } else {
          field += ch;
        }
        continue;
      }

      if (ch === "\"") {
        inQuotes = true;
        continue;
      }

      if (ch === ",") {
        row.push(field);
        field = "";
        continue;
      }

      if (ch === "\n" || ch === "\r") {
        if (ch === "\r" && next === "\n") i++;
        row.push(field);
        field = "";
        rows.push(row);
        row = [];
        continue;
      }

      field += ch;
    }

    row.push(field);
    var hasContent = false;
    for (var r = 0; r < row.length; r++) {
      if (String(row[r] || "").trim() !== "") {
        hasContent = true;
        break;
      }
    }
    if (hasContent || rows.length === 0) rows.push(row);

    return rows;
  }

  function parseTabSeparatedText(text) {
    var src = String(text == null ? "" : text).replace(/\r\n/g, "\n").replace(/\r/g, "\n");
    var lines = src.split("\n");
    var rows = [];
    for (var i = 0; i < lines.length; i++) {
      var line = String(lines[i] == null ? "" : lines[i]);
      if (line.trim() === "") continue;
      rows.push(line.split("\t"));
    }
    return rows;
  }

  function normalizePasteGridRows(raw, fallback) {
    var n = parseInt(String(raw == null ? "" : raw), 10);
    if (!isFinite(n) || n < 1) return fallback;
    return n;
  }

  function pasteGridRowCount() {
    if (!els.pasteGridRows) return 12;
    var rows = normalizePasteGridRows(els.pasteGridRows.value, 12);
    els.pasteGridRows.value = String(rows);
    return rows;
  }

  function capturePasteGridValues() {
    if (!els.pasteGridBody) return [];
    var inputs = els.pasteGridBody.querySelectorAll("input.nn-paste-cell");
    var out = [];
    for (var i = 0; i < inputs.length; i++) {
      var input = inputs[i];
      var value = String(input.value == null ? "" : input.value);
      if (value.trim() === "") continue;
      out.push({
        row: normalizePasteGridRows(input.getAttribute("data-row"), 0),
        col: String(input.getAttribute("data-col") || ""),
        value: value
      });
    }
    return out;
  }

  function restorePasteGridValues(snapshot) {
    var rows = Array.isArray(snapshot) ? snapshot : [];
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i] || {};
      var rowIndex = normalizePasteGridRows(row.row, 0);
      var colKey = String(row.col || "");
      if (colKey === "") continue;
      setPasteGridCell(rowIndex, colKey, row.value);
    }
  }

  function ensurePasteGridRowCapacity(requiredRows) {
    var needed = normalizePasteGridRows(requiredRows, 12);
    var current = pasteGridRowCount();
    if (needed <= current) return current;
    if (!els.pasteGridRows) return current;

    var snapshot = capturePasteGridValues();
    els.pasteGridRows.value = String(needed);
    var norm = normalizeConfig();
    var cfg = finalizeConfig(norm.cfg);
    buildPasteGrid(cfg, true);
    restorePasteGridValues(snapshot);
    return needed;
  }

  function pasteGridColumnKeys(cfg) {
    var cols = ["timestamp_ms"];
    for (var i = 1; i <= cfg.by; i++) cols.push("value_" + i);
    return cols;
  }

  function buildPasteGrid(cfg, force) {
    if (!els.pasteGridHead || !els.pasteGridBody) return;
    var rows = pasteGridRowCount();
    var cols = pasteGridColumnKeys(cfg);
    var meta = state.pasteGridMeta || {};
    var shouldRebuild = !!force || meta.by !== cfg.by || meta.rows !== rows;
    if (!shouldRebuild) return;

    var headHtml = ['<tr><th style="width:44px;">#</th><th style="min-width:140px;">timestamp_ms</th>'];
    for (var h = 1; h <= cfg.by; h++) {
      headHtml.push('<th style="min-width:88px;">value_' + h + "</th>");
    }
    headHtml.push("</tr>");
    els.pasteGridHead.innerHTML = headHtml.join("");

    var bodyHtml = [];
    for (var r = 0; r < rows; r++) {
      bodyHtml.push('<tr><td class="text-muted small">' + (r + 1) + "</td>");
      for (var c = 0; c < cols.length; c++) {
        var col = cols[c];
        var placeholder = col === "timestamp_ms" ? "optional" : "--";
        bodyHtml.push(
          '<td><input type="text" class="form-control form-control-sm nn-paste-cell" data-row="' + r + '" data-col="' + escapeHtml(col) + '" placeholder="' + placeholder + '"></td>'
        );
      }
      bodyHtml.push("</tr>");
    }
    els.pasteGridBody.innerHTML = bodyHtml.join("");

    state.pasteGridMeta = { by: cfg.by, rows: rows };
    state.pasteGridColumns = cols.slice();
  }

  function ensurePasteGridForConfig(cfg) {
    buildPasteGrid(cfg, false);
  }

  function setPasteGridCell(rowIndex, colKey, value) {
    if (!els.pasteGridBody) return;
    var selector = 'input.nn-paste-cell[data-row="' + rowIndex + '"][data-col="' + colKey + '"]';
    var input = els.pasteGridBody.querySelector(selector);
    if (!input) return;
    input.value = String(value == null ? "" : value).trim();
  }

  function parseGridEntries(cfg) {
    if (!els.pasteGridBody) {
      return { cleaned: [], skipped: 0, error: "Spreadsheet grid is unavailable." };
    }

    var rows = pasteGridRowCount();
    var entries = [];
    var skipped = 0;

    for (var r = 0; r < rows; r++) {
      var tsInput = els.pasteGridBody.querySelector('input.nn-paste-cell[data-row="' + r + '"][data-col="timestamp_ms"]');
      var tsRaw = tsInput ? String(tsInput.value == null ? "" : tsInput.value).trim() : "";
      var comboRaw = [];
      var hasAny = tsRaw !== "";

      for (var c = 1; c <= cfg.by; c++) {
        var col = "value_" + c;
        var valueInput = els.pasteGridBody.querySelector('input.nn-paste-cell[data-row="' + r + '"][data-col="' + col + '"]');
        var raw = valueInput ? String(valueInput.value == null ? "" : valueInput.value).trim() : "";
        if (raw !== "") hasAny = true;
        comboRaw.push(parseInt(raw, 10));
      }

      if (!hasAny) continue;
      entries.push({
        ts: tsRaw !== "" ? tsRaw : (Date.now() + r),
        combo: comboRaw
      });
    }

    var sanitized = sanitizeHistoryEntries(entries, cfg);
    skipped += sanitized.skipped;
    return { cleaned: sanitized.cleaned, skipped: skipped, error: "" };
  }

  function clearPasteGridCells() {
    if (!els.pasteGridBody) return;
    var inputs = els.pasteGridBody.querySelectorAll("input.nn-paste-cell");
    for (var i = 0; i < inputs.length; i++) {
      inputs[i].value = "";
    }
  }

  function onPasteGridInput(ev) {
    if (!ev || !ev.target) return;
    var target = ev.target;
    if (!target.classList || !target.classList.contains("nn-paste-cell")) return;
    if (!ev.clipboardData) return;

    var plain = ev.clipboardData.getData("text/plain");
    if (String(plain == null ? "" : plain).trim() === "") return;

    var rows = parseTabSeparatedText(plain);
    if (!Array.isArray(rows) || rows.length < 1) return;

    ev.preventDefault();

    var startRow = parseInt(String(target.getAttribute("data-row") || "0"), 10);
    if (!isFinite(startRow) || startRow < 0) startRow = 0;
    var startColKey = String(target.getAttribute("data-col") || "");
    var cols = Array.isArray(state.pasteGridColumns) && state.pasteGridColumns.length > 0
      ? state.pasteGridColumns.slice()
      : [];
    var startCol = cols.indexOf(startColKey);
    if (startCol < 0) startCol = 0;

    var neededRows = startRow + rows.length;
    var maxRows = ensurePasteGridRowCapacity(neededRows);
    cols = Array.isArray(state.pasteGridColumns) && state.pasteGridColumns.length > 0
      ? state.pasteGridColumns.slice()
      : cols;
    for (var r = 0; r < rows.length; r++) {
      var rowIndex = startRow + r;
      if (rowIndex >= maxRows) break;
      var line = Array.isArray(rows[r]) ? rows[r] : [];
      if (line.length === 1 && String(line[0]).indexOf(",") >= 0) {
        line = parseCsvText(String(line[0]))[0] || [];
      }
      for (var c = 0; c < line.length; c++) {
        var colIndex = startCol + c;
        if (colIndex >= cols.length) break;
        setPasteGridCell(rowIndex, cols[colIndex], line[c]);
      }
    }
  }

  function comboToChipHtml(combo) {
    if (!Array.isArray(combo) || combo.length === 0) return "<span class=\"text-muted\">--</span>";
    var out = [];
    for (var i = 0; i < combo.length; i++) {
      out.push('<span class="nn-chip">' + escapeHtml(combo[i]) + "</span>");
    }
    return out.join("");
  }

  function tableKey(cfg) {
    return "by" + cfg.by + "|" + cfg.min + "-" + cfg.max;
  }

  function keyForStorage(cfg) {
    return "b" + cfg.by + "_min" + cfg.min + "_max" + cfg.max;
  }

  function valuesInRange(cfg) {
    var out = [];
    for (var n = cfg.min; n <= cfg.max; n++) out.push(n);
    return out;
  }

  function parseComboFromString(raw) {
    var text = String(raw == null ? "" : raw);
    var parts = text.split(/[^0-9]+/);
    var out = [];
    for (var i = 0; i < parts.length; i++) {
      if (parts[i] === "") continue;
      var n = parseInt(parts[i], 10);
      if (!isFinite(n)) continue;
      out.push(n);
    }
    return out;
  }

  function sanitizeCombo(combo, cfg) {
    if (!Array.isArray(combo)) return null;
    var out = [];
    var seen = Object.create(null);
    var allowRepeat = !!(cfg && cfg.allowRepeat);
    for (var i = 0; i < combo.length; i++) {
      var n = parseInt(combo[i], 10);
      if (!isFinite(n)) continue;
      if (n < cfg.min || n > cfg.max) continue;
      if (!allowRepeat) {
        if (seen[String(n)]) continue;
        seen[String(n)] = true;
      }
      out.push(n);
    }
    if (out.length !== cfg.by) return null;
    out.sort(function (a, b) { return a - b; });
    return out;
  }

  function sanitizeHistoryEntries(entries, cfg) {
    var list = Array.isArray(entries) ? entries : [];
    var cleaned = [];
    var skipped = 0;
    var baseTs = Date.now();

    for (var i = 0; i < list.length; i++) {
      var row = list[i] || {};
      var comboRaw = Array.isArray(row.combo) ? row.combo : parseComboFromString(row.combo);
      var combo = sanitizeCombo(comboRaw, cfg);
      if (!combo) {
        skipped++;
        continue;
      }
      cleaned.push({
        ts: parseTimestampLoose(row.ts, baseTs + i),
        combo: combo
      });
    }

    return { cleaned: cleaned, skipped: skipped };
  }

  function createConfigFromBounds(by, minV, maxV, algorithm, accuracyStyle) {
    var byN = parseInt(by, 10);
    var minN = parseInt(minV, 10);
    var maxN = parseInt(maxV, 10);
    if (!isFinite(byN) || byN < TABLE_SIZE_MIN || byN > TABLE_SIZE_MAX) return null;
    if (!isFinite(minN) || minN < 0 || minN > 55) return null;
    if (!isFinite(maxN) || maxN < 0 || maxN > 55) return null;
    if (minN > maxN) return null;

    var rangeSize = maxN - minN + 1;
    var allowRepeat = isRepeatAllowedForSize(byN);
    if (!allowRepeat && rangeSize < byN) return null;

    return finalizeConfig({
      by: byN,
      min: minN,
      max: maxN,
      rangeSize: rangeSize,
      algorithm: normalizeAlgorithm(algorithm),
      accuracyStyle: normalizeAccuracyStyle(accuracyStyle),
      allowRepeat: allowRepeat
    });
  }

  function parseStoreKey(rawKey) {
    var m = /^b(\d+)_min(\d+)_max(\d+)$/i.exec(String(rawKey == null ? "" : rawKey).trim());
    if (!m) return null;
    var cfg = createConfigFromBounds(m[1], m[2], m[3], DEFAULT_ALGO, DEFAULT_STYLE);
    if (!cfg) return null;
    // Stored history/snapshots may contain repeated values from earlier policies.
    cfg.allowRepeat = true;
    return cfg;
  }

  function sanitizeStoreObject(rawStore) {
    var source = (rawStore && typeof rawStore === "object") ? rawStore : {};
    var clean = {};
    var skippedRows = 0;
    var skippedKeys = 0;
    var keys = Object.keys(source);

    for (var i = 0; i < keys.length; i++) {
      var rawKey = keys[i];
      var cfg = parseStoreKey(rawKey);
      if (!cfg) {
        skippedKeys++;
        continue;
      }

      var rawEntries = Array.isArray(source[rawKey]) ? source[rawKey] : [];
      var sanitized = sanitizeHistoryEntries(rawEntries, cfg);
      skippedRows += sanitized.skipped;

      if (!Array.isArray(clean[cfg.storeKey])) clean[cfg.storeKey] = [];
      clean[cfg.storeKey] = clean[cfg.storeKey].concat(sanitized.cleaned);
    }

    var canonicalKeys = Object.keys(clean);
    var keyCount = 0;
    var entryCount = 0;
    for (var j = 0; j < canonicalKeys.length; j++) {
      var key = canonicalKeys[j];
      var list = clean[key];
      if (!Array.isArray(list) || list.length < 1) {
        delete clean[key];
        continue;
      }
      if (list.length > MAX_HISTORY_PER_KEY) {
        list = list.slice(list.length - MAX_HISTORY_PER_KEY);
      }
      clean[key] = list;
      keyCount++;
      entryCount += list.length;
    }

    return {
      store: clean,
      keyCount: keyCount,
      entryCount: entryCount,
      skippedRows: skippedRows,
      skippedKeys: skippedKeys
    };
  }

  function mergeHistoryLists(primaryList, secondaryList) {
    var merged = [];
    var seen = Object.create(null);

    function addRows(list) {
      var rows = Array.isArray(list) ? list : [];
      for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        if (!row || typeof row !== "object") continue;

        var comboRaw = Array.isArray(row.combo) ? row.combo : parseComboFromString(row.combo);
        if (!Array.isArray(comboRaw) || comboRaw.length < 1) continue;

        var combo = [];
        for (var c = 0; c < comboRaw.length; c++) {
          var n = parseInt(comboRaw[c], 10);
          if (!isFinite(n)) continue;
          combo.push(n);
        }
        if (combo.length < 1) continue;
        combo.sort(function (a, b) { return a - b; });

        var ts = parseTimestampLoose(row.ts, Date.now());
        var fingerprint = String(ts) + "|" + combo.join(",");
        if (seen[fingerprint]) continue;
        seen[fingerprint] = true;
        merged.push({ ts: ts, combo: combo });
      }
    }

    addRows(primaryList);
    addRows(secondaryList);

    merged.sort(function (a, b) {
      var at = parseTimestampLoose(a && a.ts, 0);
      var bt = parseTimestampLoose(b && b.ts, 0);
      if (at !== bt) return at - bt;
      var ac = (a && Array.isArray(a.combo)) ? a.combo.join(",") : "";
      var bc = (b && Array.isArray(b.combo)) ? b.combo.join(",") : "";
      if (ac < bc) return -1;
      if (ac > bc) return 1;
      return 0;
    });

    if (merged.length > MAX_HISTORY_PER_KEY) {
      merged = merged.slice(merged.length - MAX_HISTORY_PER_KEY);
    }
    return merged;
  }

  function mergeStoreObjects(primaryStore, secondaryStore) {
    var primary = sanitizeStoreObject(primaryStore || {}).store;
    var secondary = sanitizeStoreObject(secondaryStore || {}).store;
    var merged = {};
    var keysMap = Object.create(null);
    var pKeys = Object.keys(primary);
    var sKeys = Object.keys(secondary);

    for (var i = 0; i < pKeys.length; i++) keysMap[pKeys[i]] = true;
    for (var j = 0; j < sKeys.length; j++) keysMap[sKeys[j]] = true;

    var keys = Object.keys(keysMap);
    for (var k = 0; k < keys.length; k++) {
      var key = keys[k];
      var joined = mergeHistoryLists(primary[key], secondary[key]);
      if (joined.length > 0) {
        merged[key] = joined;
      }
    }
    return sanitizeStoreObject(merged).store;
  }

  function storesEquivalent(leftStore, rightStore) {
    var left = sanitizeStoreObject(leftStore || {}).store;
    var right = sanitizeStoreObject(rightStore || {}).store;
    var leftKeys = Object.keys(left).sort();
    var rightKeys = Object.keys(right).sort();

    if (leftKeys.length !== rightKeys.length) return false;
    for (var i = 0; i < leftKeys.length; i++) {
      if (leftKeys[i] !== rightKeys[i]) return false;
      var lRows = Array.isArray(left[leftKeys[i]]) ? left[leftKeys[i]] : [];
      var rRows = Array.isArray(right[rightKeys[i]]) ? right[rightKeys[i]] : [];
      if (lRows.length !== rRows.length) return false;

      for (var r = 0; r < lRows.length; r++) {
        var l = lRows[r] || {};
        var rr = rRows[r] || {};
        if (parseTimestampLoose(l.ts, 0) !== parseTimestampLoose(rr.ts, 0)) return false;
        var lc = Array.isArray(l.combo) ? l.combo : [];
        var rc = Array.isArray(rr.combo) ? rr.combo : [];
        if (lc.length !== rc.length) return false;
        for (var c = 0; c < lc.length; c++) {
          if (parseInt(lc[c], 10) !== parseInt(rc[c], 10)) return false;
        }
      }
    }
    return true;
  }

  function getStoredKeyCount() {
    var summary = sanitizeStoreObject(loadStore());
    return summary.keyCount;
  }

  function normalizeConfig() {
    var allowed = activeAllowedSizes();
    var defaultBy = allowed.indexOf(6) >= 0 ? 6 : allowed[0];
    var by = normalizeTableSizeValue(els.tableSize ? els.tableSize.value : null);
    if (by == null || allowed.indexOf(by) < 0) by = defaultBy;
    var minV = clampInt(els.min.value, 0, 55, 0);
    var maxV = clampInt(els.max.value, 0, 55, 55);
    var algorithm = normalizeAlgorithm(els.algorithm ? els.algorithm.value : DEFAULT_ALGO);
    var accuracyStyle = normalizeAccuracyStyle(els.accuracyStyle ? els.accuracyStyle.value : DEFAULT_STYLE);
    var allowRepeat = isRepeatAllowedForSize(by);

    if (minV > maxV) {
      var t = minV;
      minV = maxV;
      maxV = t;
    }

    var rangeSize = maxV - minV + 1;
    var errors = [];
    if (!allowRepeat && rangeSize < by) {
      errors.push("Range must contain at least " + by + " values for table by " + by + ".");
    }
    if (rangeSize < 5) {
      errors.push("Range must contain at least 5 values for predictive analysis.");
    }

    els.tableSize.value = String(by);
    els.min.value = String(minV);
    els.max.value = String(maxV);
    if (els.algorithm) els.algorithm.value = algorithm;
    if (els.accuracyStyle) els.accuracyStyle.value = accuracyStyle;
    refreshRepeatRuleHint(by);

    return {
      ok: errors.length === 0,
      errors: errors,
      cfg: {
        by: by,
        min: minV,
        max: maxV,
        rangeSize: rangeSize,
        algorithm: algorithm,
        accuracyStyle: accuracyStyle,
        allowRepeat: allowRepeat,
        key: "",
        storeKey: ""
      }
    };
  }

  function finalizeConfig(base) {
    var cfg = {
      by: base.by,
      min: base.min,
      max: base.max,
      rangeSize: base.rangeSize,
      algorithm: normalizeAlgorithm(base.algorithm),
      accuracyStyle: normalizeAccuracyStyle(base.accuracyStyle),
      allowRepeat: !!base.allowRepeat,
      key: "",
      storeKey: ""
    };
    cfg.key = tableKey(cfg);
    cfg.storeKey = keyForStorage(cfg);
    return cfg;
  }

  function canUseRemoteStoreApi() {
    return STORE_API_URL !== "" && typeof window.fetch === "function";
  }

  function loadStoreFromLocalCache() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return {};
      var parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== "object") return {};
      return parsed;
    } catch (e) {
      return {};
    }
  }

  function saveStoreToLocalCache(store) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(store || {}));
    } catch (e) {}
  }

  function loadStore() {
    if (state.storeCache && typeof state.storeCache === "object") {
      return state.storeCache;
    }
    state.storeCache = sanitizeStoreObject(loadStoreFromLocalCache()).store;
    return state.storeCache;
  }

  function queueRemoteStoreSync() {
    if (!canUseRemoteStoreApi() || STORE_API_CSRF === "") return;
    if (state.remoteSyncTimer) {
      window.clearTimeout(state.remoteSyncTimer);
      state.remoteSyncTimer = 0;
    }
    state.remoteSyncTimer = window.setTimeout(function () {
      state.remoteSyncTimer = 0;
      flushRemoteStoreSync();
    }, 220);
  }

  function flushRemoteStoreSync() {
    if (!canUseRemoteStoreApi() || STORE_API_CSRF === "") return;
    if (state.remoteSyncInFlight) {
      state.remoteSyncQueued = true;
      return;
    }

    state.remoteSyncInFlight = true;
    var snapshot = sanitizeStoreObject(loadStore()).store;
    var body = JSON.stringify({
      action: "save_store",
      csrf_token: STORE_API_CSRF,
      store: snapshot
    });

    var finalize = function () {
      state.remoteSyncInFlight = false;
      if (state.remoteSyncQueued) {
        state.remoteSyncQueued = false;
        queueRemoteStoreSync();
      }
    };

    fetch(STORE_API_URL, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json"
      },
      body: body
    })
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (data) {
        if (!data || data.status !== "ok") {
          throw new Error((data && data.message) ? data.message : "History sync failed.");
        }
        state.remoteSyncWarned = false;
      })
      .catch(function () {
        if (!state.remoteSyncWarned) {
          state.remoteSyncWarned = true;
          setStatus("Database sync delayed. Using local cache.");
        }
      })
      .then(finalize, finalize);
  }

  function saveStore(store, opts) {
    var options = opts || {};
    var cleanSummary = sanitizeStoreObject(store || {});
    state.storeCache = cleanSummary.store;
    saveStoreToLocalCache(cleanSummary.store);
    if (!options.skipRemote) {
      queueRemoteStoreSync();
    }
  }

  function bootstrapStoreFromApi(onDone) {
    var done = (typeof onDone === "function") ? onDone : function () {};
    if (!canUseRemoteStoreApi()) {
      done(false);
      return;
    }

    fetch(STORE_API_URL, {
      method: "GET",
      credentials: "same-origin",
      headers: {
        "Accept": "application/json"
      }
    })
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (data) {
        if (!data || data.status !== "ok") {
          throw new Error((data && data.message) ? data.message : "Unable to load DB history.");
        }

        state.settings = sanitizeSettingsObject(data.settings || state.settings || defaultSettingsObject());
        state.algoSnapshots = sanitizeSnapshotsPayload(data.snapshots || {});
        renderPolicyControls();

        var remoteSummary = sanitizeStoreObject(data.store || {});
        var localSummary = sanitizeStoreObject(loadStoreFromLocalCache());
        if (remoteSummary.keyCount > 0) {
          saveStore(remoteSummary.store, { skipRemote: true });
          done(true);
          return;
        }

        // Only fall back to local cache when DB has no history yet.
        saveStore(localSummary.store, { skipRemote: true });
        if (localSummary.keyCount > 0) {
          queueRemoteStoreSync();
        }
        done(true);
      })
      .catch(function () {
        state.settings = sanitizeSettingsObject(state.settings || defaultSettingsObject());
        renderPolicyControls();
        done(false);
      });
  }

  function getHistory(cfg) {
    var store = loadStore();
    var list = store[cfg.storeKey];
    if (!Array.isArray(list)) return [];
    var sanitized = sanitizeHistoryEntries(list, cfg);
    var out = sanitized.cleaned;
    if (out.length > MAX_HISTORY_PER_KEY) out = out.slice(out.length - MAX_HISTORY_PER_KEY);
    return out;
  }

  function setHistory(cfg, entries) {
    var store = loadStore();
    var list = Array.isArray(entries) ? entries.slice() : [];
    if (list.length > MAX_HISTORY_PER_KEY) {
      list = list.slice(list.length - MAX_HISTORY_PER_KEY);
    }
    store[cfg.storeKey] = list;
    saveStore(store);
  }

  function appendHistory(cfg, rows) {
    var history = getHistory(cfg);
    var now = Date.now();
    for (var i = 0; i < rows.length; i++) {
      history.push({
        ts: now + i,
        combo: rows[i].slice()
      });
    }
    setHistory(cfg, history);
  }

  function clearHistory(cfg) {
    var store = loadStore();
    delete store[cfg.storeKey];
    saveStore(store);
  }

  function exportHistoryAsJson(cfg) {
    var history = getHistory(cfg);
    if (history.length < 1) {
      window.alert("No history entries to export for " + cfg.key + ".");
      return;
    }

    var payload = {
      format: "neighbor_numbers_history_v1",
      exported_at: Date.now(),
      table: {
        by: cfg.by,
        min: cfg.min,
        max: cfg.max,
        key: cfg.key,
        store_key: cfg.storeKey,
        algorithm: cfg.algorithm
      },
      entries: history
    };

    var stamp = formatFileStamp(Date.now());
    var file = "neighbor-history_" + cfg.key.replace(/[|]/g, "_").replace(/[^a-zA-Z0-9_-]/g, "") + "_" + stamp + ".json";
    downloadTextFile(file, "application/json;charset=utf-8", JSON.stringify(payload, null, 2));
    setStatus("JSON exported");
  }

  function exportHistoryAsCsv(cfg) {
    var history = getHistory(cfg);
    if (history.length < 1) {
      window.alert("No history entries to export for " + cfg.key + ".");
      return;
    }

    var headers = ["timestamp_ms", "timestamp_iso", "by", "min", "max"];
    for (var i = 1; i <= cfg.by; i++) headers.push("value_" + i);
    var lines = [headers.map(csvEscape).join(",")];

    for (var r = 0; r < history.length; r++) {
      var row = history[r];
      var cols = [
        row.ts,
        formatDateTime(row.ts),
        cfg.by,
        cfg.min,
        cfg.max
      ];
      var combo = Array.isArray(row.combo) ? row.combo : [];
      for (var v = 0; v < cfg.by; v++) cols.push(combo[v] == null ? "" : combo[v]);
      lines.push(cols.map(csvEscape).join(","));
    }

    var stamp = formatFileStamp(Date.now());
    var file = "neighbor-history_" + cfg.key.replace(/[|]/g, "_").replace(/[^a-zA-Z0-9_-]/g, "") + "_" + stamp + ".csv";
    downloadTextFile(file, "text/csv;charset=utf-8", lines.join("\n"));
    setStatus("CSV exported");
  }

  function exportAllHistoryAsJson() {
    var summary = sanitizeStoreObject(loadStore());
    if (summary.keyCount < 1) {
      window.alert("No stored history keys to export.");
      return;
    }

    var payload = {
      format: "neighbor_numbers_all_keys_v1",
      exported_at: Date.now(),
      storage_key: STORAGE_KEY,
      key_count: summary.keyCount,
      entry_count: summary.entryCount,
      keys: summary.store
    };

    var stamp = formatFileStamp(Date.now());
    var file = "neighbor-history_all-keys_" + stamp + ".json";
    downloadTextFile(file, "application/json;charset=utf-8", JSON.stringify(payload, null, 2));
    setStatus("All keys exported");
  }

  function parseTabularImportRows(rows, cfg) {
    if (!Array.isArray(rows) || rows.length < 2) {
      return { cleaned: [], skipped: 0 };
    }

    var header = rows[0].map(function (h) { return String(h || "").trim().toLowerCase(); });
    var idx = Object.create(null);
    for (var h = 0; h < header.length; h++) {
      if (header[h] !== "") idx[header[h]] = h;
    }

    var entries = [];
    var skipped = 0;
    for (var i = 1; i < rows.length; i++) {
      var line = rows[i] || [];
      var ts = Date.now() + i;
      if (idx.timestamp_ms != null) ts = parseTimestampLoose(line[idx.timestamp_ms], ts);
      else if (idx.timestamp_iso != null) ts = parseTimestampLoose(line[idx.timestamp_iso], ts);
      else if (idx.timestamp != null) ts = parseTimestampLoose(line[idx.timestamp], ts);
      else if (idx.ts != null) ts = parseTimestampLoose(line[idx.ts], ts);

      var comboRaw = [];
      var hasValueCols = true;
      for (var c = 1; c <= cfg.by; c++) {
        var key = "value_" + c;
        if (idx[key] == null) {
          hasValueCols = false;
          break;
        }
      }

      if (hasValueCols) {
        for (var c2 = 1; c2 <= cfg.by; c2++) {
          comboRaw.push(parseInt(line[idx["value_" + c2]], 10));
        }
      } else if (idx.combo != null) {
        comboRaw = parseComboFromString(line[idx.combo]);
      } else {
        skipped++;
        continue;
      }

      entries.push({ ts: ts, combo: comboRaw });
    }

    var sanitized = sanitizeHistoryEntries(entries, cfg);
    sanitized.skipped += skipped;
    return sanitized;
  }

  function parseCsvImportEntries(text, cfg) {
    return parseTabularImportRows(parseCsvText(text), cfg);
  }

  function parseXlsxImportEntries(arrayBuffer, cfg) {
    if (!window.XLSX || typeof window.XLSX.read !== "function" || !window.XLSX.utils || typeof window.XLSX.utils.sheet_to_json !== "function") {
      return { cleaned: [], skipped: 0, error: "XLSX parser is unavailable. Refresh the page and try again, or import CSV/JSON." };
    }

    var workbook = null;
    try {
      workbook = window.XLSX.read(arrayBuffer, { type: "array", cellDates: false, raw: false });
    } catch (e) {
      return { cleaned: [], skipped: 0, error: "Invalid XLSX format." };
    }

    if (!workbook || !Array.isArray(workbook.SheetNames) || workbook.SheetNames.length < 1) {
      return { cleaned: [], skipped: 0, error: "No worksheet found in XLSX file." };
    }

    var firstSheetName = workbook.SheetNames[0];
    var firstSheet = workbook.Sheets[firstSheetName];
    if (!firstSheet) {
      return { cleaned: [], skipped: 0, error: "No worksheet found in XLSX file." };
    }

    var rows = [];
    try {
      rows = window.XLSX.utils.sheet_to_json(firstSheet, { header: 1, blankrows: false, defval: "" });
    } catch (e2) {
      return { cleaned: [], skipped: 0, error: "Unable to read worksheet rows from XLSX file." };
    }

    return parseTabularImportRows(rows, cfg);
  }

  function parseJsonImportEntries(text, cfg) {
    var parsed = null;
    try {
      parsed = JSON.parse(String(text == null ? "" : text));
    } catch (e) {
      return { cleaned: [], skipped: 0, error: "Invalid JSON format." };
    }

    var entries = [];

    if (Array.isArray(parsed)) {
      entries = parsed;
    } else if (parsed && typeof parsed === "object") {
      if (Array.isArray(parsed.entries)) {
        entries = parsed.entries;
      } else if (Array.isArray(parsed[cfg.storeKey])) {
        entries = parsed[cfg.storeKey];
      } else if (parsed.table && Array.isArray(parsed.rows)) {
        entries = parsed.rows;
      } else {
        var values = Object.keys(parsed).map(function (k) { return parsed[k]; });
        for (var i = 0; i < values.length; i++) {
          if (Array.isArray(values[i])) {
            entries = values[i];
            break;
          }
        }
      }
    }

    var sanitized = sanitizeHistoryEntries(entries, cfg);
    return { cleaned: sanitized.cleaned, skipped: sanitized.skipped, error: "" };
  }

  function parseLineComboImportEntries(text, cfg) {
    var src = String(text == null ? "" : text).replace(/\r\n/g, "\n").replace(/\r/g, "\n");
    var lines = src.split("\n");
    var entries = [];
    var skipped = 0;
    for (var i = 0; i < lines.length; i++) {
      var line = String(lines[i] == null ? "" : lines[i]).trim();
      if (line === "") continue;
      var nums = parseComboFromString(line);
      if (nums.length > cfg.by) {
        nums = nums.slice(nums.length - cfg.by);
      }
      if (nums.length !== cfg.by) {
        skipped++;
        continue;
      }
      entries.push({
        ts: Date.now() + i,
        combo: nums
      });
    }

    var sanitized = sanitizeHistoryEntries(entries, cfg);
    return {
      cleaned: sanitized.cleaned,
      skipped: sanitized.skipped + skipped,
      error: ""
    };
  }

  function parsePastedImportEntries(text, cfg) {
    var src = String(text == null ? "" : text).trim();
    if (src === "") {
      return { cleaned: [], skipped: 0, error: "Paste area is empty." };
    }

    var first = src.charAt(0);
    if (first === "[" || first === "{") {
      return parseJsonImportEntries(src, cfg);
    }

    var rows = src.indexOf("\t") >= 0 ? parseTabSeparatedText(src) : parseCsvText(src);
    var tabular = parseTabularImportRows(rows, cfg);
    if (Array.isArray(tabular.cleaned) && tabular.cleaned.length > 0) {
      return { cleaned: tabular.cleaned, skipped: tabular.skipped || 0, error: "" };
    }

    var lineBased = parseLineComboImportEntries(src, cfg);
    if (Array.isArray(lineBased.cleaned) && lineBased.cleaned.length > 0) {
      return lineBased;
    }

    return {
      cleaned: [],
      skipped: Math.max(tabular.skipped || 0, lineBased.skipped || 0),
      error: "No valid rows were detected. Include header columns (value_1...value_n or combo), or one full combo per line."
    };
  }

  function parseAllKeysJson(text) {
    var parsed = null;
    try {
      parsed = JSON.parse(String(text == null ? "" : text));
    } catch (e) {
      return { error: "Invalid JSON format.", summary: null };
    }

    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
      return { error: "Invalid backup format. Expected a JSON object.", summary: null };
    }

    var source = null;
    if (parsed.table && Array.isArray(parsed.entries)) {
      var by = parseInt(parsed.table.by, 10);
      var minV = parseInt(parsed.table.min, 10);
      var maxV = parseInt(parsed.table.max, 10);
      var cfg = createConfigFromBounds(by, minV, maxV, DEFAULT_ALGO, DEFAULT_STYLE);
      if (!cfg) {
        return { error: "Single-key payload has invalid by/min/max values.", summary: null };
      }
      source = {};
      source[cfg.storeKey] = parsed.entries;
    } else if (parsed.keys && typeof parsed.keys === "object" && !Array.isArray(parsed.keys)) {
      source = parsed.keys;
    } else {
      source = parsed;
    }

    var summary = sanitizeStoreObject(source);
    if (summary.keyCount < 1) {
      return { error: "No valid key history found in the import file.", summary: summary };
    }

    return { error: "", summary: summary };
  }

  function importAllStore(summary) {
    if (!summary || !summary.store || typeof summary.store !== "object") return false;

    var replace = window.confirm(
      "Import mode for all table keys:\n" +
      "OK = Replace all stored keys\n" +
      "Cancel = Merge with existing keys"
    );

    if (replace) {
      saveStore(summary.store);
      return true;
    }

    var existing = sanitizeStoreObject(loadStore()).store;
    var keys = Object.keys(summary.store);
    for (var i = 0; i < keys.length; i++) {
      var key = keys[i];
      var incoming = Array.isArray(summary.store[key]) ? summary.store[key] : [];
      var current = Array.isArray(existing[key]) ? existing[key] : [];
      var merged = current.concat(incoming);
      if (merged.length > MAX_HISTORY_PER_KEY) {
        merged = merged.slice(merged.length - MAX_HISTORY_PER_KEY);
      }
      if (merged.length > 0) existing[key] = merged;
      else delete existing[key];
    }
    saveStore(existing);
    return true;
  }

  function importEntriesIntoHistory(cfg, importedEntries) {
    var cleanEntries = Array.isArray(importedEntries) ? importedEntries : [];
    if (cleanEntries.length < 1) {
      window.alert("No valid entries found for " + cfg.key + ".");
      return false;
    }

    var replace = window.confirm(
      "Import mode for " + cfg.key + ":\\n" +
      "OK = Replace existing history\\n" +
      "Cancel = Append to existing history"
    );

    var existing = replace ? [] : getHistory(cfg);
    var merged = existing.concat(cleanEntries);
    if (merged.length > MAX_HISTORY_PER_KEY) {
      merged = merged.slice(merged.length - MAX_HISTORY_PER_KEY);
    }
    setHistory(cfg, merged);
    return true;
  }

  function jaccardCombo(a, b) {
    if (!Array.isArray(a) || !Array.isArray(b) || a.length < 1 || b.length < 1) return 0;
    var setA = Object.create(null);
    for (var i = 0; i < a.length; i++) setA[a[i]] = true;
    var inter = 0;
    for (var j = 0; j < b.length; j++) {
      if (setA[b[j]]) inter++;
    }
    var union = a.length + b.length - inter;
    if (union <= 0) return 0;
    return inter / union;
  }

  function tanhApprox(x) {
    if (Math.tanh) return Math.tanh(x);
    var e2 = Math.exp(2 * x);
    return (e2 - 1) / (e2 + 1);
  }

  function sigmoid(x) {
    return 1 / (1 + Math.exp(-x));
  }

  function normalizeProfileWeights(profile) {
    var p = profile || {};
    var sum =
      Math.max(0, p.wFreq || 0) +
      Math.max(0, p.wRecency || 0) +
      Math.max(0, p.wCo || 0) +
      Math.max(0, p.wRecent || 0) +
      Math.max(0, p.wTransition || 0) +
      Math.max(0, p.wOverdue || 0);

    if (sum <= 0) {
      p.wFreq = 0.25;
      p.wRecency = 0.22;
      p.wCo = 0.13;
      p.wRecent = 0.12;
      p.wTransition = 0.18;
      p.wOverdue = 0.10;
      return p;
    }

    p.wFreq = Math.max(0, p.wFreq || 0) / sum;
    p.wRecency = Math.max(0, p.wRecency || 0) / sum;
    p.wCo = Math.max(0, p.wCo || 0) / sum;
    p.wRecent = Math.max(0, p.wRecent || 0) / sum;
    p.wTransition = Math.max(0, p.wTransition || 0) / sum;
    p.wOverdue = Math.max(0, p.wOverdue || 0) / sum;
    return p;
  }

  function cloneProfile(profile) {
    var p = profile || {};
    return {
      baseMix: p.baseMix,
      inLastPenalty: p.inLastPenalty,
      wFreq: p.wFreq,
      wRecency: p.wRecency,
      wCo: p.wCo,
      wRecent: p.wRecent,
      wTransition: p.wTransition,
      wOverdue: p.wOverdue,
      tuned: !!p.tuned,
      sampleCount: p.sampleCount || 0,
      objective: p.objective || 0,
      avgHit: p.avgHit || 0,
      style: p.style || DEFAULT_STYLE
    };
  }

  function baseProfileForAlgorithm(algo) {
    var key = normalizeAlgorithm(algo);
    if (key === "random_forest") {
      return normalizeProfileWeights({
        baseMix: 0.80,
        inLastPenalty: 0.95,
        wFreq: 0.34,
        wRecency: 0.24,
        wCo: 0.12,
        wRecent: 0.09,
        wTransition: 0.13,
        wOverdue: 0.08
      });
    }
    if (key === "xgboost") {
      return normalizeProfileWeights({
        baseMix: 0.78,
        inLastPenalty: 0.97,
        wFreq: 0.31,
        wRecency: 0.22,
        wCo: 0.14,
        wRecent: 0.10,
        wTransition: 0.15,
        wOverdue: 0.08
      });
    }
    if (key === "neural_network") {
      return normalizeProfileWeights({
        baseMix: 0.74,
        inLastPenalty: 0.97,
        wFreq: 0.25,
        wRecency: 0.24,
        wCo: 0.15,
        wRecent: 0.11,
        wTransition: 0.17,
        wOverdue: 0.08
      });
    }
    if (key === "linear") {
      return normalizeProfileWeights({
        baseMix: 0.70,
        inLastPenalty: 0.97,
        wFreq: 0.36,
        wRecency: 0.27,
        wCo: 0.11,
        wRecent: 0.10,
        wTransition: 0.09,
        wOverdue: 0.07
      });
    }
    if (key === "knn") {
      return normalizeProfileWeights({
        baseMix: 0.72,
        inLastPenalty: 0.96,
        wFreq: 0.24,
        wRecency: 0.20,
        wCo: 0.12,
        wRecent: 0.10,
        wTransition: 0.25,
        wOverdue: 0.09
      });
    }
    if (key === "naive_bayes") {
      return normalizeProfileWeights({
        baseMix: 0.72,
        inLastPenalty: 0.98,
        wFreq: 0.30,
        wRecency: 0.22,
        wCo: 0.16,
        wRecent: 0.11,
        wTransition: 0.10,
        wOverdue: 0.11
      });
    }
    if (key === "sma") {
      return normalizeProfileWeights({
        baseMix: 0.76,
        inLastPenalty: 0.99,
        wFreq: 0.32,
        wRecency: 0.20,
        wCo: 0.10,
        wRecent: 0.22,
        wTransition: 0.08,
        wOverdue: 0.08
      });
    }
    if (key === "sgma") {
      return normalizeProfileWeights({
        baseMix: 0.82,
        inLastPenalty: 0.96,
        wFreq: 0.35,
        wRecency: 0.22,
        wCo: 0.08,
        wRecent: 0.16,
        wTransition: 0.08,
        wOverdue: 0.11
      });
    }
    return normalizeProfileWeights({
      baseMix: 0.78,
      inLastPenalty: 0.97,
      wFreq: 0.31,
      wRecency: 0.22,
      wCo: 0.14,
      wRecent: 0.10,
      wTransition: 0.15,
      wOverdue: 0.08
    });
  }

  function styleAdjustedProfile(baseProfile, style) {
    var p = cloneProfile(baseProfile);
    var s = normalizeAccuracyStyle(style);
    if (s === "hybrid") {
      p.baseMix -= 0.01;
      p.wFreq *= 1.10;
      p.wRecency *= 1.08;
      p.wRecent *= 1.10;
      p.wTransition *= 1.18;
      p.wOverdue *= 0.92;
      p.inLastPenalty *= 0.99;
    } else if (s === "conservative") {
      p.baseMix += 0.06;
      p.wFreq *= 1.16;
      p.wRecency *= 1.12;
      p.wTransition *= 0.88;
      p.wOverdue *= 0.85;
      p.inLastPenalty *= 0.97;
    } else if (s === "momentum") {
      p.baseMix -= 0.04;
      p.wFreq *= 0.92;
      p.wRecent *= 1.20;
      p.wRecency *= 1.10;
      p.wTransition *= 1.28;
      p.wOverdue *= 0.95;
      p.inLastPenalty *= 1.02;
    } else if (s === "exploratory") {
      p.baseMix -= 0.08;
      p.wFreq *= 0.90;
      p.wCo *= 1.15;
      p.wTransition *= 1.08;
      p.wOverdue *= 1.35;
      p.inLastPenalty *= 1.04;
    }

    p.baseMix = Math.min(0.90, Math.max(0.45, p.baseMix));
    p.inLastPenalty = Math.min(1.04, Math.max(0.88, p.inLastPenalty));
    p.style = s;
    return normalizeProfileWeights(p);
  }

  function candidateProfile(baseProfile, delta) {
    var d = delta || {};
    var p = cloneProfile(baseProfile);
    p.baseMix = (p.baseMix || 0.75) + (d.baseMix || 0);
    p.inLastPenalty = (p.inLastPenalty || 0.97) * (d.penaltyMult || 1);
    p.wFreq = (p.wFreq || 0.20) * (d.freqMult || 1);
    p.wRecency = (p.wRecency || 0.20) * (d.recencyMult || 1);
    p.wCo = (p.wCo || 0.15) * (d.coMult || 1);
    p.wRecent = (p.wRecent || 0.15) * (d.recentMult || 1);
    p.wTransition = (p.wTransition || 0.20) * (d.transitionMult || 1);
    p.wOverdue = (p.wOverdue || 0.10) * (d.overdueMult || 1);
    p.baseMix = Math.min(0.90, Math.max(0.45, p.baseMix));
    p.inLastPenalty = Math.min(1.04, Math.max(0.88, p.inLastPenalty));
    return normalizeProfileWeights(p);
  }

  function tuningCandidateDeltas(style) {
    var s = normalizeAccuracyStyle(style);
    var base = [
      { baseMix: 0.00, freqMult: 1.00, recencyMult: 1.00, coMult: 1.00, recentMult: 1.00, transitionMult: 1.00, overdueMult: 1.00, penaltyMult: 1.00 },
      { baseMix: 0.04, freqMult: 1.08, recencyMult: 1.06, coMult: 0.96, recentMult: 0.95, transitionMult: 0.92, overdueMult: 0.90, penaltyMult: 0.98 },
      { baseMix: -0.04, freqMult: 0.92, recencyMult: 1.00, coMult: 1.08, recentMult: 1.12, transitionMult: 1.20, overdueMult: 1.06, penaltyMult: 1.02 },
      { baseMix: 0.02, freqMult: 1.00, recencyMult: 1.10, coMult: 1.00, recentMult: 1.02, transitionMult: 1.06, overdueMult: 0.92, penaltyMult: 0.99 },
      { baseMix: -0.02, freqMult: 0.95, recencyMult: 0.96, coMult: 1.14, recentMult: 1.08, transitionMult: 1.10, overdueMult: 1.16, penaltyMult: 1.03 }
    ];

    if (s === "hybrid") {
      base.push(
        { baseMix: -0.01, freqMult: 1.08, recencyMult: 1.08, coMult: 1.02, recentMult: 1.10, transitionMult: 1.20, overdueMult: 0.92, penaltyMult: 0.99 },
        { baseMix: -0.03, freqMult: 1.04, recencyMult: 1.10, coMult: 1.04, recentMult: 1.14, transitionMult: 1.24, overdueMult: 0.95, penaltyMult: 1.00 }
      );
    } else if (s === "conservative") {
      base.push(
        { baseMix: 0.06, freqMult: 1.12, recencyMult: 1.12, coMult: 0.94, recentMult: 0.90, transitionMult: 0.86, overdueMult: 0.84, penaltyMult: 0.97 },
        { baseMix: 0.08, freqMult: 1.16, recencyMult: 1.08, coMult: 0.92, recentMult: 0.88, transitionMult: 0.84, overdueMult: 0.82, penaltyMult: 0.96 }
      );
    } else if (s === "momentum") {
      base.push(
        { baseMix: -0.06, freqMult: 0.88, recencyMult: 1.14, coMult: 1.00, recentMult: 1.20, transitionMult: 1.28, overdueMult: 0.92, penaltyMult: 1.03 },
        { baseMix: -0.08, freqMult: 0.84, recencyMult: 1.10, coMult: 1.06, recentMult: 1.24, transitionMult: 1.34, overdueMult: 0.96, penaltyMult: 1.04 }
      );
    } else if (s === "exploratory") {
      base.push(
        { baseMix: -0.08, freqMult: 0.86, recencyMult: 0.96, coMult: 1.18, recentMult: 1.04, transitionMult: 1.12, overdueMult: 1.28, penaltyMult: 1.05 },
        { baseMix: -0.10, freqMult: 0.80, recencyMult: 0.94, coMult: 1.22, recentMult: 1.10, transitionMult: 1.14, overdueMult: 1.34, penaltyMult: 1.06 }
      );
    }

    return base;
  }

  function historySignature(history) {
    var list = Array.isArray(history) ? history : [];
    if (list.length < 1) return "0";
    var start = Math.max(0, list.length - 22);
    var checksum = 0;
    var mod = 1000000007;
    for (var i = start; i < list.length; i++) {
      var combo = list[i] && Array.isArray(list[i].combo) ? list[i].combo : [];
      for (var c = 0; c < combo.length; c++) {
        checksum = (checksum * 131 + (combo[c] + 17)) % mod;
      }
    }
    var lastTs = list[list.length - 1] && list[list.length - 1].ts ? list[list.length - 1].ts : 0;
    return list.length + "|" + lastTs + "|" + checksum;
  }

  function snapshotMapKey(cfg, style) {
    return String(cfg.storeKey) + "|" + normalizeAccuracyStyle(style);
  }

  function sanitizeTopFiveForSnapshot(rawTop, cfg) {
    var list = Array.isArray(rawTop) ? rawTop : [];
    var out = [];
    for (var i = 0; i < list.length; i++) {
      var row = list[i] || {};
      var n = parseInt(row.number, 10);
      if (!isFinite(n)) continue;
      if (n < cfg.min || n > cfg.max) continue;
      var score = Number(row.score);
      if (!isFinite(score) || score < 0) score = 0;
      out.push({ number: n, score: score });
      if (out.length >= 5) break;
    }
    return out;
  }

  function sanitizeAlgorithmSnapshot(rawRow, cfg, algo) {
    if (!rawRow || typeof rawRow !== "object") return null;
    var key = normalizeAlgorithm(algo);
    var comboRaw = Array.isArray(rawRow.combo)
      ? rawRow.combo
      : (rawRow.prediction && Array.isArray(rawRow.prediction.combo) ? rawRow.prediction.combo : null);
    var combo = sanitizeCombo(comboRaw, cfg);
    if (!combo || combo.length !== cfg.by) return null;

    var topRaw = Array.isArray(rawRow.topFive)
      ? rawRow.topFive
      : (Array.isArray(rawRow.top_five) ? rawRow.top_five : (rawRow.prediction && rawRow.prediction.topFive ? rawRow.prediction.topFive : []));
    var topFive = sanitizeTopFiveForSnapshot(topRaw, cfg);

    var hitRate = null;
    if (rawRow.hitRate != null && isFinite(Number(rawRow.hitRate))) {
      hitRate = Number(rawRow.hitRate);
    } else if (rawRow.hit_rate != null && isFinite(Number(rawRow.hit_rate))) {
      hitRate = Number(rawRow.hit_rate);
    }
    if (hitRate != null) {
      if (hitRate < 0) hitRate = 0;
      if (hitRate > 1) hitRate = 1;
    }

    var formula = String(rawRow.formula || algorithmBaseFormulaText(key));
    if (formula.length > 220) formula = formula.slice(0, 220);

    var computedAt = parseTimestampLoose(rawRow.computedAt || rawRow.computed_at_ms, Date.now());

    return {
      algorithm: key,
      combo: combo,
      topFive: topFive,
      hitRate: hitRate,
      formula: formula,
      computedAt: computedAt
    };
  }

  function sanitizeSnapshotsPayload(raw) {
    var source = (raw && typeof raw === "object") ? raw : {};
    var keys = Object.keys(source);
    var out = Object.create(null);

    for (var i = 0; i < keys.length; i++) {
      var bucketRaw = source[keys[i]];
      if (!bucketRaw || typeof bucketRaw !== "object") continue;

      var storeKey = String(bucketRaw.store_key || bucketRaw.storeKey || "");
      var cfg = parseStoreKey(storeKey);
      if (!cfg) continue;

      var style = normalizeAccuracyStyle(bucketRaw.accuracy_style || bucketRaw.accuracyStyle || DEFAULT_STYLE);
      var hs = String(bucketRaw.history_signature || bucketRaw.historySignature || "");
      var updatedAt = parseTimestampLoose(bucketRaw.updated_at_ms || bucketRaw.updatedAtMs, 0);
      var algosRaw = (bucketRaw.algorithms && typeof bucketRaw.algorithms === "object") ? bucketRaw.algorithms : {};

      var algos = Object.create(null);
      for (var a = 0; a < COMPARE_ALGOS.length; a++) {
        var algo = COMPARE_ALGOS[a];
        var sanitized = sanitizeAlgorithmSnapshot(algosRaw[algo], cfg, algo);
        if (!sanitized) continue;
        algos[algo] = sanitized;
      }
      if (Object.keys(algos).length < 1) continue;

      out[snapshotMapKey(cfg, style)] = {
        storeKey: cfg.storeKey,
        accuracyStyle: style,
        historySignature: hs,
        updatedAtMs: updatedAt,
        algorithms: algos
      };
    }

    return out;
  }

  function getSnapshotBucket(cfg, style) {
    var key = snapshotMapKey(cfg, style);
    var map = state.algoSnapshots && typeof state.algoSnapshots === "object" ? state.algoSnapshots : {};
    return map[key] && typeof map[key] === "object" ? map[key] : null;
  }

  function getSnapshotBucketForHistory(cfg, history, style) {
    var bucket = getSnapshotBucket(cfg, style);
    if (!bucket) return null;
    var sig = historySignature(history);
    if (String(bucket.historySignature || "") !== sig) return null;
    return bucket;
  }

  function getSavedAlgorithmResult(cfg, history, style, algo) {
    var bucket = getSnapshotBucketForHistory(cfg, history, style);
    if (!bucket || !bucket.algorithms) return null;
    var key = normalizeAlgorithm(algo);
    return bucket.algorithms[key] || null;
  }

  function setSnapshotBucket(cfg, style, historySig, algorithms, updatedAtMs) {
    var cleanAlgos = Object.create(null);
    for (var i = 0; i < COMPARE_ALGOS.length; i++) {
      var algo = COMPARE_ALGOS[i];
      var row = sanitizeAlgorithmSnapshot((algorithms || {})[algo], cfg, algo);
      if (!row) continue;
      cleanAlgos[algo] = row;
    }
    if (Object.keys(cleanAlgos).length < 1) {
      delete state.algoSnapshots[snapshotMapKey(cfg, style)];
      return null;
    }
    var bucket = {
      storeKey: cfg.storeKey,
      accuracyStyle: normalizeAccuracyStyle(style),
      historySignature: String(historySig || ""),
      updatedAtMs: parseTimestampLoose(updatedAtMs, Date.now()),
      algorithms: cleanAlgos
    };
    state.algoSnapshots[snapshotMapKey(cfg, style)] = bucket;
    return bucket;
  }

  function clearSnapshotBucket(cfg, style) {
    delete state.algoSnapshots[snapshotMapKey(cfg, style)];
  }

  function snapshotPayloadForApi(bucket) {
    var payload = Object.create(null);
    if (!bucket || !bucket.algorithms) return payload;
    for (var i = 0; i < COMPARE_ALGOS.length; i++) {
      var algo = COMPARE_ALGOS[i];
      var row = bucket.algorithms[algo];
      if (!row) continue;
      payload[algo] = {
        hit_rate: row.hitRate,
        combo: row.combo,
        top_five: row.topFive,
        formula: row.formula,
        computed_at_ms: row.computedAt
      };
    }
    return payload;
  }

  function saveSnapshotsRemote(cfg, style, historySig, bucket, onDone) {
    var done = (typeof onDone === "function") ? onDone : function () {};
    if (!canUseRemoteStoreApi() || STORE_API_CSRF === "") {
      done(false);
      return;
    }

    var body = JSON.stringify({
      action: "save_snapshots",
      csrf_token: STORE_API_CSRF,
      store_key: cfg.storeKey,
      accuracy_style: normalizeAccuracyStyle(style),
      history_signature: String(historySig || "0"),
      snapshots: snapshotPayloadForApi(bucket)
    });

    fetch(STORE_API_URL, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json"
      },
      body: body
    })
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (data) {
        if (!data || data.status !== "ok") {
          throw new Error((data && data.message) ? data.message : "Snapshot save failed.");
        }
        done(true);
      })
      .catch(function () {
        done(false);
      });
  }

  function ensureRecalcModal() {
    if (!els.recalcModal || !window.bootstrap || !window.bootstrap.Modal) return null;
    if (!state.recalcModal) {
      state.recalcModal = new window.bootstrap.Modal(els.recalcModal, {
        backdrop: "static",
        keyboard: false
      });
    }
    return state.recalcModal;
  }

  function showRecalcModal(title, text) {
    var modal = ensureRecalcModal();
    if (!modal) return;
    if (els.recalcTitle) els.recalcTitle.textContent = title || "Recalculating All Algorithms";
    if (els.recalcText) els.recalcText.textContent = text || "Please wait while model outputs are updated.";
    if (els.recalcProgressBar) els.recalcProgressBar.style.width = "0%";
    if (els.recalcProgressPct) els.recalcProgressPct.textContent = "0%";
    modal.show();
  }

  function updateRecalcModalProgress(doneCount, totalCount, detailText) {
    var total = Math.max(1, parseInt(totalCount, 10) || 1);
    var done = Math.max(0, Math.min(total, parseInt(doneCount, 10) || 0));
    var pct = Math.round((done / total) * 100);
    if (els.recalcProgressBar) els.recalcProgressBar.style.width = pct + "%";
    if (els.recalcProgressPct) els.recalcProgressPct.textContent = pct + "%";
    if (detailText && els.recalcText) els.recalcText.textContent = detailText;
  }

  function hideRecalcModal() {
    var modal = ensureRecalcModal();
    if (!modal) return;
    modal.hide();
  }

  function computeAlgorithmSnapshot(cfg, history, algo, style) {
    var key = normalizeAlgorithm(algo);
    var prediction = buildPrediction(cfg, history, key, style);
    var hitRate = estimateHitRate(cfg, history, key, style, prediction.profile || null);
    return sanitizeAlgorithmSnapshot({
      combo: prediction.combo,
      topFive: prediction.topFive,
      hitRate: hitRate,
      formula: algorithmBaseFormulaText(key),
      computedAt: Date.now()
    }, cfg, key);
  }

  function recalcAllAlgorithms(cfg, history, style, opts, onDone) {
    var options = opts || {};
    var done = (typeof onDone === "function") ? onDone : function () {};
    var list = Array.isArray(history) ? history.slice() : [];
    var styleKey = normalizeAccuracyStyle(style);
    var algos = COMPARE_ALGOS.slice();
    var total = algos.length;
    var idx = 0;
    var resultMap = Object.create(null);
    var showModal = !!options.showModal;
    var delayMs = showModal ? 70 : 0;

    if (showModal) {
      showRecalcModal(
        options.modalTitle || "Recalculating All Algorithms",
        options.modalText || "Please wait while model outputs are updated."
      );
    }

    function finish() {
      var historySig = historySignature(list);
      var bucket = setSnapshotBucket(cfg, styleKey, historySig, resultMap, Date.now());
      saveSnapshotsRemote(cfg, styleKey, historySig, bucket, function () {
        if (showModal) hideRecalcModal();
        done(bucket);
      });
    }

    function step() {
      if (idx >= total) {
        finish();
        return;
      }
      var algo = algos[idx];
      var row = computeAlgorithmSnapshot(cfg, list, algo, styleKey);
      if (row) resultMap[algo] = row;
      idx++;
      if (showModal) {
        updateRecalcModalProgress(idx, total, "Computing " + algorithmLabel(algo) + " (" + idx + "/" + total + ")");
      }
      if (delayMs > 0) {
        window.setTimeout(step, delayMs);
      } else {
        step();
      }
    }

    step();
  }

  function tuningCacheKey(cfg, algo, style, history) {
    return cfg.storeKey + "|" + normalizeAlgorithm(algo) + "|" + normalizeAccuracyStyle(style) + "|" + historySignature(history);
  }

  function normalizeScoreList(scores) {
    var out = Array.isArray(scores) ? scores : [];
    var minScore = Infinity;
    var maxScore = -Infinity;
    for (var m = 0; m < out.length; m++) {
      if (out[m].score < minScore) minScore = out[m].score;
      if (out[m].score > maxScore) maxScore = out[m].score;
    }
    if (maxScore > minScore) {
      for (var mm = 0; mm < out.length; mm++) {
        out[mm].score = (out[mm].score - minScore) / (maxScore - minScore);
      }
    } else {
      for (var mmm = 0; mmm < out.length; mmm++) out[mmm].score = 1;
    }

    out.sort(function (a, b) {
      if (b.score !== a.score) return b.score - a.score;
      return a.number - b.number;
    });
    return out;
  }

  function scoreNumbersCore(cfg, history, algorithm, profile) {
    var values = valuesInRange(cfg);
    var scores = [];
    var algo = normalizeAlgorithm(algorithm);
    var p = normalizeProfileWeights(cloneProfile(profile || styleAdjustedProfile(baseProfileForAlgorithm(algo), cfg.accuracyStyle)));

    if (!Array.isArray(history) || history.length === 0) {
      var center = (cfg.min + cfg.max) / 2;
      var spread = Math.max(1, (cfg.max - cfg.min) / 2);
      for (var f0 = 0; f0 < values.length; f0++) {
        var nCenter = values[f0];
        var centrality = 1 - Math.abs(nCenter - center) / spread;
        var fallbackScore = (0.75 * centrality) + (0.25 * ((values.length - f0) / values.length));
        scores.push({ number: nCenter, score: fallbackScore });
      }
      return normalizeScoreList(scores);
    }

    var freq = Object.create(null);
    var recency = Object.create(null);
    var co = Object.create(null);
    var recent = Object.create(null);
    var transition = Object.create(null);
    var smaShort = Object.create(null);
    var smaLong = Object.create(null);
    var lastSeen = Object.create(null);
    var shortWindowStart = Math.max(0, history.length - 8);
    var longWindowStart = Math.max(0, history.length - 16);
    for (var z = 0; z < values.length; z++) {
      var n0 = values[z];
      freq[n0] = 0;
      recency[n0] = 0;
      co[n0] = 0;
      recent[n0] = 0;
      transition[n0] = 0;
      smaShort[n0] = 0;
      smaLong[n0] = 0;
      lastSeen[n0] = -1;
    }

    var lastCombo = history[history.length - 1].combo || [];
    var lastSet = Object.create(null);
    for (var l = 0; l < lastCombo.length; l++) lastSet[lastCombo[l]] = true;

    for (var i = 0; i < history.length; i++) {
      var entry = history[i] || {};
      var combo = Array.isArray(entry.combo) ? entry.combo : [];
      if (combo.length < 1) continue;

      var age = history.length - 1 - i;
      var recWeight = Math.pow(0.93, age);
      var inRecentWindow = i >= Math.max(0, history.length - 25);

      var overlap = 0;
      for (var c2 = 0; c2 < combo.length; c2++) {
        if (lastSet[combo[c2]]) overlap++;
      }

      for (var c3 = 0; c3 < combo.length; c3++) {
        var n = combo[c3];
        if (freq[n] == null) continue;
        freq[n] += 1;
        recency[n] += recWeight;
        if (inRecentWindow) recent[n] += 1;
        if (i >= shortWindowStart) smaShort[n] += 1;
        if (i >= longWindowStart) smaLong[n] += 1;
        if (overlap > 0 && !lastSet[n]) co[n] += overlap;
        lastSeen[n] = i;
      }

      if (i < history.length - 1) {
        var nextCombo = history[i + 1] && Array.isArray(history[i + 1].combo) ? history[i + 1].combo : [];
        var similarity = jaccardCombo(combo, lastCombo);
        if (similarity > 0) {
          for (var nx = 0; nx < nextCombo.length; nx++) {
            var nNext = nextCombo[nx];
            if (transition[nNext] != null) transition[nNext] += similarity;
          }
        }
      }
    }

    var maxFreq = 0;
    var maxRec = 0;
    var maxCo = 0;
    var maxRecent = 0;
    var maxTransition = 0;
    var maxSmaShort = 0;
    var maxSmaLong = 0;
    var maxOverdue = 0;
    var overdueMap = Object.create(null);
    for (var j = 0; j < values.length; j++) {
      var n1 = values[j];
      var overdue = lastSeen[n1] < 0 ? history.length : (history.length - 1 - lastSeen[n1]);
      overdueMap[n1] = overdue;

      if (freq[n1] > maxFreq) maxFreq = freq[n1];
      if (recency[n1] > maxRec) maxRec = recency[n1];
      if (co[n1] > maxCo) maxCo = co[n1];
      if (recent[n1] > maxRecent) maxRecent = recent[n1];
      if (transition[n1] > maxTransition) maxTransition = transition[n1];
      if (smaShort[n1] > maxSmaShort) maxSmaShort = smaShort[n1];
      if (smaLong[n1] > maxSmaLong) maxSmaLong = smaLong[n1];
      if (overdue > maxOverdue) maxOverdue = overdue;
    }
    var hasTransitionSignal = maxTransition > 0;
    if (maxFreq <= 0) maxFreq = 1;
    if (maxRec <= 0) maxRec = 1;
    if (maxCo <= 0) maxCo = 1;
    if (maxRecent <= 0) maxRecent = 1;
    if (maxTransition <= 0) maxTransition = 1;
    if (maxSmaShort <= 0) maxSmaShort = 1;
    if (maxSmaLong <= 0) maxSmaLong = 1;
    if (maxOverdue <= 0) maxOverdue = 1;

    for (var k = 0; k < values.length; k++) {
      var n2 = values[k];
      var f = freq[n2] / maxFreq;
      var r = recency[n2] / maxRec;
      var h = co[n2] / maxCo;
      var rw = recent[n2] / maxRecent;
      var tw = transition[n2] / maxTransition;
      var smaS = smaShort[n2] / maxSmaShort;
      var smaL = smaLong[n2] / maxSmaLong;
      var ow = overdueMap[n2] / maxOverdue;
      var inLast = !!lastSet[n2];
      var baseScore = 0;

      if (algo === "random_forest") {
        var tree1 = f > 0.70 ? 1 : f * 0.90;
        var tree2 = r > 0.60 ? 1 : (0.35 * r) + (0.65 * rw);
        var tree3 = tw > 0.45 ? 1 : (0.80 * ((tw + h) / 2));
        var tree4 = (ow > 0.18 && ow < 0.82) ? 1 : (0.60 * ow);
        var tree5 = ((f + r + tw) > 1.50) ? 1 : ((f + r + tw) / 1.50);
        baseScore = (0.26 * tree1) + (0.22 * tree2) + (0.20 * tree3) + (0.16 * tree4) + (0.16 * tree5);
      } else if (algo === "xgboost") {
        var weak1 = (0.42 * f) + (0.26 * r) + (0.14 * tw);
        var residual = rw - ((0.62 * f) + (0.38 * r));
        var weak2 = (0.34 * r) + (0.28 * tw) + (0.22 * h) + (0.16 * Math.max(0, residual));
        var weak3 = (0.50 * weak1) + (0.50 * weak2) - (0.08 * Math.abs(ow - 0.48));
        baseScore = (0.44 * weak1) + (0.34 * weak2) + (0.22 * weak3);
      } else if (algo === "neural_network") {
        var inLastNum = inLast ? 1 : 0;
        var x1 = (1.40 * f) + (1.10 * r) + (0.90 * tw) + (0.50 * h) - (0.60 * ow) - 1.10;
        var x2 = (1.20 * rw) + (0.70 * tw) + (0.50 * f) - 0.90;
        var hidden1 = tanhApprox(x1);
        var hidden2 = tanhApprox(x2);
        baseScore = sigmoid((1.20 * hidden1) + (0.90 * hidden2) + (0.60 * h) - (0.30 * inLastNum));
      } else if (algo === "linear") {
        baseScore = (0.54 * f) + (0.28 * r) + (0.10 * rw) + (0.05 * tw) + (0.03 * ow);
      } else if (algo === "knn") {
        baseScore = (0.62 * tw) + (0.23 * f) + (0.15 * r);
        if (!hasTransitionSignal) baseScore = (0.55 * f) + (0.30 * r) + (0.15 * h);
      } else if (algo === "naive_bayes") {
        var pf = Math.max(0.01, f);
        var pr = Math.max(0.01, r);
        var pc = Math.max(0.01, h);
        var pt = Math.max(0.01, tw);
        var po = Math.max(0.01, 1 - Math.abs(ow - 0.45));
        var logProb =
          (0.38 * Math.log(pf)) +
          (0.23 * Math.log(pr)) +
          (0.15 * Math.log(pc)) +
          (0.16 * Math.log(pt)) +
          (0.08 * Math.log(po));
        baseScore = Math.exp(logProb);
      } else if (algo === "sma") {
        baseScore = (0.56 * smaL) + (0.28 * smaS) + (0.10 * f) + (0.06 * r);
      } else if (algo === "sgma") {
        var stability = 1 - Math.min(1, Math.abs(smaS - smaL));
        var gate = 0.70 + (0.30 * stability);
        var sgmaCore = (0.52 * smaL) + (0.22 * smaS) + (0.16 * r) + (0.10 * f);
        baseScore = sgmaCore * gate;
      } else {
        baseScore += 0.42 * f;
        baseScore += 0.25 * r;
        baseScore += 0.14 * tw;
        baseScore += 0.10 * h;
        baseScore += 0.09 * ow;
        var residual1 = Math.max(0, rw - ((0.60 * f) + (0.40 * r)));
        var residual2 = Math.max(0, tw - (0.50 * h));
        baseScore += 0.12 * residual1;
        baseScore += 0.08 * residual2;
      }

      var adaptive =
        (p.wFreq * f) +
        (p.wRecency * r) +
        (p.wCo * h) +
        (p.wRecent * rw) +
        (p.wTransition * tw) +
        (p.wOverdue * ow);

      var mix = Math.min(0.90, Math.max(0.45, p.baseMix));
      var score = (mix * baseScore) + ((1 - mix) * adaptive);
      if (inLast) score *= Math.min(1.04, Math.max(0.88, p.inLastPenalty));
      if (score < 0) score = 0;

      scores.push({ number: n2, score: score });
    }

    return normalizeScoreList(scores);
  }

  function evaluateProfileCandidate(cfg, history, algorithm, accuracyStyle, profile) {
    if (!Array.isArray(history) || history.length < 14) {
      return { objective: -Infinity, tests: 0, avgHit: 0 };
    }

    var start = Math.max(6, history.length - 80);
    var tests = 0;
    var sum = 0;
    var weightedSum = 0;
    var weightedDenom = 0;
    var values = [];
    var uniquePredict = Object.create(null);

    for (var i = start; i < history.length; i++) {
      var baseStart = Math.max(0, i - 220);
      var base = history.slice(baseStart, i);
      if (base.length < 6) continue;

      var scored = scoreNumbersCore(cfg, base, algorithm, profile);
      var predicted = scored.slice(0, 5).map(function (it) { return it.number; });
      var actualCombo = history[i].combo || [];
      var actualSet = Object.create(null);
      for (var a = 0; a < actualCombo.length; a++) actualSet[actualCombo[a]] = true;

      var hits = 0;
      for (var p = 0; p < predicted.length; p++) {
        uniquePredict[predicted[p]] = true;
        if (actualSet[predicted[p]]) hits++;
      }

      var ratio = hits / 5;
      values.push(ratio);
      sum += ratio;
      var recencyWeight = 1 + ((i - start) / Math.max(1, history.length - start - 1));
      weightedSum += ratio * recencyWeight;
      weightedDenom += recencyWeight;
      tests++;
    }

    if (tests < 1) return { objective: -Infinity, tests: 0, avgHit: 0 };

    var avg = sum / tests;
    var variance = 0;
    for (var v = 0; v < values.length; v++) {
      variance += Math.pow(values[v] - avg, 2);
    }
    variance = variance / tests;
    var stdev = Math.sqrt(Math.max(0, variance));
    var recentWeighted = weightedDenom > 0 ? (weightedSum / weightedDenom) : avg;
    var diversity = Object.keys(uniquePredict).length / Math.max(1, cfg.rangeSize);
    var style = normalizeAccuracyStyle(accuracyStyle);
    var objective = avg;

    if (style === "hybrid") {
      objective = (0.52 * avg) + (0.30 * recentWeighted) - (0.12 * stdev) + (0.06 * diversity);
    } else if (style === "conservative") {
      objective = avg - (0.25 * stdev) + (0.05 * recentWeighted);
    } else if (style === "momentum") {
      objective = (0.40 * avg) + (0.55 * recentWeighted) + (0.05 * diversity);
    } else if (style === "exploratory") {
      objective = (0.55 * avg) + (0.20 * recentWeighted) + (0.25 * diversity) - (0.10 * stdev);
    } else {
      objective = avg + (0.05 * recentWeighted);
    }

    return {
      objective: objective,
      tests: tests,
      avgHit: avg
    };
  }

  function tuneProfileFromHistory(cfg, history, algorithm, accuracyStyle) {
    var base = styleAdjustedProfile(baseProfileForAlgorithm(algorithm), accuracyStyle);
    if (!Array.isArray(history) || history.length < 14) {
      base.tuned = false;
      base.sampleCount = Array.isArray(history) ? history.length : 0;
      base.objective = 0;
      base.avgHit = 0;
      base.style = normalizeAccuracyStyle(accuracyStyle);
      return base;
    }

    var deltas = tuningCandidateDeltas(accuracyStyle);
    var bestProfile = cloneProfile(base);
    var bestEval = evaluateProfileCandidate(cfg, history, algorithm, accuracyStyle, bestProfile);

    for (var i = 0; i < deltas.length; i++) {
      var candidate = candidateProfile(base, deltas[i]);
      var ev = evaluateProfileCandidate(cfg, history, algorithm, accuracyStyle, candidate);
      if (ev.objective > bestEval.objective) {
        bestEval = ev;
        bestProfile = candidate;
      }
    }

    bestProfile.tuned = bestEval.tests > 0;
    bestProfile.sampleCount = bestEval.tests;
    bestProfile.objective = bestEval.objective;
    bestProfile.avgHit = bestEval.avgHit;
    bestProfile.style = normalizeAccuracyStyle(accuracyStyle);
    return bestProfile;
  }

  function resolvePredictionProfile(cfg, history, algorithm, accuracyStyle) {
    var style = normalizeAccuracyStyle(accuracyStyle);
    var base = styleAdjustedProfile(baseProfileForAlgorithm(algorithm), style);
    if (!Array.isArray(history) || history.length < 14) {
      base.tuned = false;
      base.sampleCount = Array.isArray(history) ? history.length : 0;
      base.objective = 0;
      base.avgHit = 0;
      base.style = style;
      return base;
    }

    var key = tuningCacheKey(cfg, algorithm, style, history);
    var cached = state.tuningCache[key];
    if (cached) return cloneProfile(cached);

    var tuned = tuneProfileFromHistory(cfg, history, algorithm, style);
    state.tuningCache[key] = cloneProfile(tuned);
    return tuned;
  }

  function scoreNumbers(cfg, history, algorithm, accuracyStyle, forcedProfile) {
    var profile = forcedProfile
      ? cloneProfile(forcedProfile)
      : resolvePredictionProfile(cfg, history, algorithm, accuracyStyle);
    var scores = scoreNumbersCore(cfg, history, algorithm, profile);
    return {
      scores: scores,
      profile: profile
    };
  }

  function buildPrediction(cfg, history, algorithm, accuracyStyle, forcedProfile) {
    var scored = scoreNumbers(cfg, history, algorithm, accuracyStyle, forcedProfile);
    var scores = Array.isArray(scored.scores) ? scored.scores : [];
    var topFive = scores.slice(0, 5);
    var combo = [];
    if (cfg.allowRepeat) {
      var pool = scores.slice(0, Math.max(1, Math.min(scores.length, Math.max(5, cfg.by))));
      if (pool.length > 0) {
        for (var i = 0; i < cfg.by; i++) {
          combo.push(pool[i % pool.length].number);
        }
      }
    } else {
      combo = scores.slice(0, cfg.by).map(function (it) { return it.number; });
    }
    combo.sort(function (a, b) { return a - b; });
    return {
      topFive: topFive,
      combo: combo,
      scores: scores,
      profile: scored.profile
    };
  }

  function estimateHitRate(cfg, history, algorithm, accuracyStyle, forcedProfile) {
    if (!Array.isArray(history) || history.length < 12) return null;

    var start = Math.max(6, history.length - 120);
    var tests = 0;
    var sum = 0;

    for (var i = start; i < history.length; i++) {
      var baseStart = Math.max(0, i - 240);
      var base = history.slice(baseStart, i);
      if (base.length < 6) continue;

      var pred = buildPrediction(cfg, base, algorithm, accuracyStyle, forcedProfile || null);
      var predicted = pred.topFive.map(function (it) { return it.number; });

      var actualCombo = history[i].combo || [];
      var actualSet = Object.create(null);
      for (var a = 0; a < actualCombo.length; a++) actualSet[actualCombo[a]] = true;

      var hits = 0;
      for (var p = 0; p < predicted.length; p++) {
        if (actualSet[predicted[p]]) hits++;
      }

      sum += hits / 5;
      tests++;
    }

    if (tests < 1) return null;
    return sum / tests;
  }

  function percentageText(value) {
    if (value == null || !isFinite(value)) return "--";
    return Math.round(value * 100) + "%";
  }

  function topFiveMiniHtml(top) {
    var list = Array.isArray(top) ? top : [];
    if (list.length < 1) return '<span class="text-muted">--</span>';
    var html = ['<div class="nn-mini-bars">'];
    for (var i = 0; i < list.length; i++) {
      var item = list[i] || {};
      var idx = Math.round((isFinite(item.score) ? item.score : 0) * 100);
      var width = Math.max(3, Math.min(100, idx));
      html.push(
        '<div class="nn-mini-bar">' +
          '<span class="nn-mini-bar-num">' + escapeHtml(item.number) + "</span>" +
          '<span class="nn-mini-bar-track"><span class="nn-mini-bar-fill" style="width:' + width + '%;"></span></span>' +
          '<span class="nn-mini-bar-pct">' + escapeHtml(idx) + "%</span>" +
        "</div>"
      );
    }
    html.push("</div>");
    return html.join("");
  }

  function algorithmBaseFormulaText(algo) {
    var key = normalizeAlgorithm(algo);
    if (key === "random_forest") {
      return "base = 0.26*Tree1 + 0.22*Tree2 + 0.20*Tree3 + 0.16*Tree4 + 0.16*Tree5";
    }
    if (key === "xgboost") {
      return "base = 0.44*Weak1 + 0.34*Weak2 + 0.22*Weak3 (residual boosted)";
    }
    if (key === "neural_network") {
      return "base = sigmoid(1.20*h1 + 0.90*h2 + 0.60*C - 0.30*I)";
    }
    if (key === "linear") {
      return "base = 0.54F + 0.28R + 0.10RW + 0.05T + 0.03O";
    }
    if (key === "knn") {
      return "base = 0.62T + 0.23F + 0.15R (fallback: 0.55F + 0.30R + 0.15C)";
    }
    if (key === "naive_bayes") {
      return "base = exp(0.38ln(F)+0.23ln(R)+0.15ln(C)+0.16ln(T)+0.08ln(Po))";
    }
    if (key === "sma") {
      return "base = 0.56*SMA_long + 0.28*SMA_short + 0.10F + 0.06R";
    }
    if (key === "sgma") {
      return "base = (SMA blend) * stability_gate, gate = 0.70 + 0.30*stability";
    }
    return "base = 0.42F + 0.25R + 0.14T + 0.10C + 0.09O + residuals";
  }

  function profileFormulaHtml(profile, style) {
    var p = profile || {};
    var mix = isFinite(p.baseMix) ? p.baseMix : 0.75;
    var inv = 1 - mix;
    var pen = isFinite(p.inLastPenalty) ? p.inLastPenalty : 0.97;
    var wF = isFinite(p.wFreq) ? p.wFreq : 0;
    var wR = isFinite(p.wRecency) ? p.wRecency : 0;
    var wC = isFinite(p.wCo) ? p.wCo : 0;
    var wRW = isFinite(p.wRecent) ? p.wRecent : 0;
    var wT = isFinite(p.wTransition) ? p.wTransition : 0;
    var wO = isFinite(p.wOverdue) ? p.wOverdue : 0;
    var tuned = p.tuned ? ("tuned windows: " + (p.sampleCount || 0)) : "default profile";

    var line1 = "adaptive = " +
      wF.toFixed(2) + "F + " +
      wR.toFixed(2) + "R + " +
      wC.toFixed(2) + "C + " +
      wRW.toFixed(2) + "RW + " +
      wT.toFixed(2) + "T + " +
      wO.toFixed(2) + "O";

    var line2 = "final = (" + mix.toFixed(2) + " * base) + (" + inv.toFixed(2) + " * adaptive); inLast x" + pen.toFixed(2);
    var line3 = "style = " + accuracyStyleLabel(style) + " (" + tuned + ")";

    return (
      '<div class="nn-formula">' +
        escapeHtml(line1) + "<br>" +
        escapeHtml(line2) + "<br>" +
        escapeHtml(line3) +
      "</div>"
    );
  }

  function compareAlgoData(cfg, history, style) {
    var out = [];
    var list = Array.isArray(history) ? history : [];
    var useStyle = normalizeAccuracyStyle(style);
    for (var i = 0; i < COMPARE_ALGOS.length; i++) {
      var algo = COMPARE_ALGOS[i];
      var saved = getSavedAlgorithmResult(cfg, list, useStyle, algo);
      if (saved) {
        out.push({
          algo: algo,
          profile: null,
          prediction: {
            combo: saved.combo.slice(),
            topFive: Array.isArray(saved.topFive) ? saved.topFive.slice() : [],
            scores: [],
            profile: null
          },
          hitRate: saved.hitRate,
          formula: saved.formula || algorithmBaseFormulaText(algo),
          fromCache: true
        });
        continue;
      }

      var profile = resolvePredictionProfile(cfg, list, algo, useStyle);
      var pred = buildPrediction(cfg, list, algo, useStyle, profile);
      var hitRate = estimateHitRate(cfg, list, algo, useStyle, profile);
      out.push({
        algo: algo,
        profile: profile,
        prediction: pred,
        hitRate: hitRate,
        formula: algorithmBaseFormulaText(algo),
        fromCache: false
      });
    }
    return out;
  }

  function renderCompareHitChart(data) {
    if (!els.compareHitChart) return;
    var list = Array.isArray(data) ? data : [];
    if (list.length < 1) {
      els.compareHitChart.innerHTML = '<div class="text-muted small">No algorithm comparison data yet.</div>';
      return;
    }

    var maxRate = 0;
    for (var i = 0; i < list.length; i++) {
      var hr = list[i].hitRate;
      if (hr != null && hr > maxRate) maxRate = hr;
    }
    if (maxRate <= 0) maxRate = 1;

    var html = [];
    for (var j = 0; j < list.length; j++) {
      var row = list[j];
      var hr2 = row.hitRate;
      var width = hr2 == null ? 0 : Math.max(2, Math.round((hr2 / maxRate) * 100));
      var color = ALGO_COLORS[row.algo] || "linear-gradient(90deg, #2563eb, #14b8a6)";
      html.push(
        '<div class="nn-compare-row">' +
          '<div class="nn-compare-label">' + escapeHtml(algorithmLabel(row.algo)) + '</div>' +
          '<div class="nn-compare-track">' +
            '<div class="nn-compare-fill" style="width:' + width + '%;background:' + escapeHtml(color) + ';"></div>' +
          '</div>' +
          '<div class="nn-compare-value">' + escapeHtml(percentageText(hr2)) + '</div>' +
        '</div>'
      );
    }
    els.compareHitChart.innerHTML = html.join("");
  }

  function renderCompareTable(cfg, data, style) {
    if (!els.compareBody) return;
    var list = Array.isArray(data) ? data : [];
    if (list.length < 1) {
      els.compareBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Generate history to compare algorithms.</td></tr>';
      return;
    }

    var html = [];
    for (var i = 0; i < list.length; i++) {
      var row = list[i];
      var algo = row.algo;
      var selected = normalizeAlgorithm(cfg.algorithm) === normalizeAlgorithm(algo);
      var nameCell = escapeHtml(algorithmLabel(algo));
      if (selected) {
        nameCell += ' <span class="text-primary small">(Selected)</span>';
      }

      var hit = row.hitRate == null
        ? '<span class="text-muted">--</span>'
        : '<span class="fw-bold">' + escapeHtml(percentageText(row.hitRate)) + "</span>";

      var combo = row.prediction && Array.isArray(row.prediction.combo) && row.prediction.combo.length === cfg.by
        ? comboToChipHtml(row.prediction.combo)
        : '<span class="text-muted">--</span>';

      var top = row.prediction ? topFiveMiniHtml(row.prediction.topFive) : '<span class="text-muted">--</span>';

      var formulaHtml = '<div class="nn-formula">' + escapeHtml(row.formula || algorithmBaseFormulaText(algo)) + "</div>";
      if (row.profile) {
        formulaHtml += profileFormulaHtml(row.profile, style);
      } else if (row.fromCache) {
        formulaHtml += '<div class="text-muted small mt-1">Loaded from saved snapshot.</div>';
      }

      html.push(
        "<tr>" +
          "<td>" + nameCell + "</td>" +
          "<td>" + hit + "</td>" +
          "<td>" + combo + "</td>" +
          "<td>" + top + "</td>" +
          "<td>" + formulaHtml + "</td>" +
        "</tr>"
      );
    }

    els.compareBody.innerHTML = html.join("");
  }

  function renderAlgorithmComparison(cfg, history) {
    var list = Array.isArray(history) ? history : getHistory(cfg);
    var style = normalizeAccuracyStyle(cfg.accuracyStyle);
    var data = compareAlgoData(cfg, list, style);
    var cachedCount = 0;
    for (var i = 0; i < data.length; i++) {
      if (data[i] && data[i].fromCache) cachedCount++;
    }

    renderCompareHitChart(data);
    renderCompareTable(cfg, data, style);

    if (els.compareMeta) {
      els.compareMeta.textContent =
        COMPARE_ALGOS.length + " algorithms | style " + accuracyStyleLabel(style) + " | history entries: " + list.length + " | saved snapshots: " + cachedCount + "/" + COMPARE_ALGOS.length;
    }
  }

  function modelVariantCacheKey(cfg, history, style, variant) {
    return (
      cfg.storeKey + "|" +
      normalizeAccuracyStyle(style) + "|" +
      String(variant && variant.id ? variant.id : "") + "|" +
      historySignature(history)
    );
  }

  function resolveVariantPrediction(cfg, history, style, variant) {
    var v = variant || {};
    var algo = normalizeAlgorithm(v.algo || DEFAULT_ALGO);
    var cacheKey = modelVariantCacheKey(cfg, history, style, v);
    if (state.modelVariantCache[cacheKey]) {
      return state.modelVariantCache[cacheKey];
    }

    var baseProfile = resolvePredictionProfile(cfg, history, algo, style);
    var forcedProfile = v.delta ? candidateProfile(baseProfile, v.delta) : cloneProfile(baseProfile);
    forcedProfile.style = normalizeAccuracyStyle(style);
    forcedProfile.tuned = !!baseProfile.tuned;
    forcedProfile.sampleCount = baseProfile.sampleCount || 0;

    var prediction = buildPrediction(cfg, history, algo, style, forcedProfile);
    var hitRate = estimateHitRate(cfg, history, algo, style, forcedProfile);
    var result = {
      variant: v,
      algo: algo,
      profile: forcedProfile,
      prediction: prediction,
      hitRate: hitRate
    };
    state.modelVariantCache[cacheKey] = result;
    return result;
  }

  function modelVisualSvg(groupId) {
    var id = String(groupId || "").toLowerCase();
    var markerId = "nnArr_" + id.replace(/[^a-z0-9]+/g, "_");
    var arrowRef = "url(#" + markerId + ")";
    var baseOpen =
      '<svg class="nn-model-svg nn-model-svg-diagram" viewBox="0 0 460 250" role="img" aria-label="' + escapeHtml(id) + ' model visual">' +
        '<defs>' +
          '<marker id="' + markerId + '" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">' +
            '<path d="M0,0 L8,4 L0,8 z" fill="#334155"></path>' +
          "</marker>" +
        "</defs>" +
        '<style>' +
          '.d-title{font:700 12px Georgia,Times New Roman,serif;fill:#0f172a;letter-spacing:.2px}' +
          '.d-label{font:600 10px Georgia,Times New Roman,serif;fill:#1e293b}' +
          '.d-mini{font:500 9px Georgia,Times New Roman,serif;fill:#475569}' +
          '.d-line{stroke:#334155;stroke-width:1.5;fill:none;stroke-linecap:round}' +
          '.d-branch{stroke:#475569;stroke-width:1.2;fill:none;stroke-linecap:round}' +
          '.d-soft{stroke:#64748b;stroke-width:0.95;fill:none;stroke-linecap:round;opacity:.62}' +
          '.d-link{stroke:#64748b;stroke-width:0.85;fill:none;stroke-linecap:round;opacity:.42}' +
          '.d-axis{stroke:#334155;stroke-width:1.5;fill:none}' +
          '.d-grid{stroke:#94a3b8;stroke-width:0.75;fill:none;opacity:.56}' +
        "</style>" +
        '<rect x="8" y="8" width="444" height="234" rx="12" fill="#f8fafc" stroke="#cbd5e1"></rect>';
    var end = "</svg>";

    function makeLayerNodes(x, startY, count, stepY) {
      var out = [];
      for (var i = 0; i < count; i++) {
        out.push({ x: x, y: startY + (i * stepY) });
      }
      return out;
    }

    function connectLayers(a, b, cls) {
      var html = [];
      var klass = cls || "d-soft";
      for (var i = 0; i < a.length; i++) {
        for (var j = 0; j < b.length; j++) {
          html.push('<line class="' + klass + '" x1="' + a[i].x + '" y1="' + a[i].y + '" x2="' + b[j].x + '" y2="' + b[j].y + '"></line>');
        }
      }
      return html.join("");
    }

    function drawNodes(nodes, r, fill, stroke) {
      var html = [];
      for (var i = 0; i < nodes.length; i++) {
        html.push('<circle cx="' + nodes[i].x + '" cy="' + nodes[i].y + '" r="' + r + '" fill="' + fill + '" stroke="' + stroke + '"></circle>');
      }
      return html.join("");
    }

    if (id === "random_forest") {
      function rfSeed(cx, label) {
        return (
          '<circle cx="' + cx + '" cy="104" r="15" fill="#f8fafc" stroke="#94a3b8" stroke-width="1.2"></circle>' +
          '<text class="d-mini" x="' + cx + '" y="107" text-anchor="middle">' + escapeHtml(label) + "</text>"
        );
      }

      function rfTree(cx, indexLabel, weakLabel, highlightLeaf) {
        var mids = [cx - 26, cx + 26];
        var leaves = [cx - 38, cx - 14, cx + 14, cx + 38];
        var out = "";
        out += '<line class="d-line" x1="' + cx + '" y1="119" x2="' + cx + '" y2="127"></line>';
        out += '<circle cx="' + cx + '" cy="131" r="6.6" fill="#84cc16" stroke="#65a30d" stroke-width="1.2"></circle>';
        out += '<line class="d-branch" x1="' + cx + '" y1="137" x2="' + mids[0] + '" y2="152"></line>';
        out += '<line class="d-branch" x1="' + cx + '" y1="137" x2="' + mids[1] + '" y2="152"></line>';
        out += '<circle cx="' + mids[0] + '" cy="154" r="5.4" fill="#3b82f6" stroke="#2563eb" stroke-width="1.15"></circle>';
        out += '<circle cx="' + mids[1] + '" cy="154" r="5.4" fill="#3b82f6" stroke="#2563eb" stroke-width="1.15"></circle>';
        out += '<line class="d-branch" x1="' + mids[0] + '" y1="159" x2="' + leaves[0] + '" y2="173"></line>';
        out += '<line class="d-branch" x1="' + mids[0] + '" y1="159" x2="' + leaves[1] + '" y2="173"></line>';
        out += '<line class="d-branch" x1="' + mids[1] + '" y1="159" x2="' + leaves[2] + '" y2="173"></line>';
        out += '<line class="d-branch" x1="' + mids[1] + '" y1="159" x2="' + leaves[3] + '" y2="173"></line>';
        for (var i = 0; i < leaves.length; i++) {
          var isAccent = i === highlightLeaf;
          out += '<circle cx="' + leaves[i] + '" cy="175" r="4.7" fill="' + (isAccent ? "#a3e635" : "#3b82f6") + '" stroke="' + (isAccent ? "#65a30d" : "#2563eb") + '" stroke-width="1.05"></circle>';
        }
        out += '<text class="d-label" x="' + cx + '" y="193" text-anchor="middle">' + escapeHtml(indexLabel) + "</text>";
        out += '<text class="d-mini" x="' + cx + '" y="204" text-anchor="middle">' + escapeHtml(weakLabel) + "</text>";
        return out;
      }

      return (
        baseOpen +
          '<text class="d-title" x="230" y="24" text-anchor="middle">Training Dataset</text>' +
          '<circle cx="230" cy="40" r="22" fill="#ecfeff" stroke="#22d3ee" stroke-width="1.45"></circle>' +
          '<text class="d-mini" x="230" y="43" text-anchor="middle">x o +</text>' +
          '<line class="d-line" x1="230" y1="62" x2="230" y2="72" marker-end="' + arrowRef + '"></line>' +
          '<text class="d-label" x="230" y="84" text-anchor="middle">Bagging</text>' +
          '<line class="d-line" x1="230" y1="88" x2="96" y2="104"></line>' +
          '<line class="d-line" x1="230" y1="88" x2="230" y2="104"></line>' +
          '<line class="d-line" x1="230" y1="88" x2="364" y2="104"></line>' +
          rfSeed(96, "x + o") +
          rfSeed(230, "x o +") +
          rfSeed(364, "o + x") +
          rfTree(96, "1st Tree", "1st weak classifier", 1) +
          rfTree(230, "2nd Tree", "2nd weak classifier", 2) +
          '<text class="d-title" x="300" y="140" text-anchor="middle">...</text>' +
          rfTree(364, "Nth Tree", "Nth weak classifier", 0) +
          '<line class="d-line" x1="20" y1="210" x2="440" y2="210"></line>' +
          '<line class="d-line" x1="230" y1="203" x2="230" y2="215" marker-end="' + arrowRef + '"></line>' +
          '<text class="d-title" x="230" y="229" text-anchor="middle">Final classifier</text>' +
          '<text class="d-mini" x="230" y="238" text-anchor="middle">(majority vote rule)</text>' +
        end
      );
    }

    if (id === "xgboost") {
      function xgbTree(cx, cy) {
        return (
          '<circle cx="' + cx + '" cy="' + cy + '" r="6.3" fill="#059669" stroke="#047857" stroke-width="1.2"></circle>' +
          '<line class="d-branch" x1="' + cx + '" y1="' + (cy + 6) + '" x2="' + (cx - 14) + '" y2="' + (cy + 22) + '"></line>' +
          '<line class="d-branch" x1="' + cx + '" y1="' + (cy + 6) + '" x2="' + (cx + 14) + '" y2="' + (cy + 22) + '"></line>' +
          '<circle cx="' + (cx - 14) + '" cy="' + (cy + 24) + '" r="5.1" fill="#10b981" stroke="#059669" stroke-width="1.1"></circle>' +
          '<circle cx="' + (cx + 14) + '" cy="' + (cy + 24) + '" r="5.1" fill="#10b981" stroke="#059669" stroke-width="1.1"></circle>' +
          '<line class="d-branch" x1="' + (cx - 14) + '" y1="' + (cy + 29) + '" x2="' + (cx - 25) + '" y2="' + (cy + 43) + '"></line>' +
          '<line class="d-branch" x1="' + (cx - 14) + '" y1="' + (cy + 29) + '" x2="' + (cx - 3) + '" y2="' + (cy + 43) + '"></line>' +
          '<line class="d-branch" x1="' + (cx + 14) + '" y1="' + (cy + 29) + '" x2="' + (cx + 3) + '" y2="' + (cy + 43) + '"></line>' +
          '<line class="d-branch" x1="' + (cx + 14) + '" y1="' + (cy + 29) + '" x2="' + (cx + 25) + '" y2="' + (cy + 43) + '"></line>' +
          '<circle cx="' + (cx - 25) + '" cy="' + (cy + 45) + '" r="3.6" fill="#34d399" stroke="#059669" stroke-width="1"></circle>' +
          '<circle cx="' + (cx - 3) + '" cy="' + (cy + 45) + '" r="3.6" fill="#34d399" stroke="#059669" stroke-width="1"></circle>' +
          '<circle cx="' + (cx + 3) + '" cy="' + (cy + 45) + '" r="3.6" fill="#34d399" stroke="#059669" stroke-width="1"></circle>' +
          '<circle cx="' + (cx + 25) + '" cy="' + (cy + 45) + '" r="3.6" fill="#34d399" stroke="#059669" stroke-width="1"></circle>'
        );
      }

      return (
        baseOpen +
          '<rect x="190" y="16" width="80" height="24" rx="6" fill="#f8fafc" stroke="#0f766e" stroke-width="1.2"></rect>' +
          '<text class="d-title" x="230" y="32" text-anchor="middle">Data Set</text>' +
          '<line class="d-line" x1="230" y1="40" x2="94" y2="58"></line>' +
          '<line class="d-line" x1="230" y1="40" x2="230" y2="58"></line>' +
          '<line class="d-line" x1="230" y1="40" x2="366" y2="58"></line>' +
          '<rect x="80" y="58" width="28" height="20" rx="5" fill="#ffffff" stroke="#10b981" stroke-width="1.1"></rect>' +
          '<rect x="216" y="58" width="28" height="20" rx="5" fill="#ffffff" stroke="#10b981" stroke-width="1.1"></rect>' +
          '<rect x="352" y="58" width="28" height="20" rx="5" fill="#ffffff" stroke="#10b981" stroke-width="1.1"></rect>' +
          '<text class="d-mini" x="94" y="72" text-anchor="middle">D1</text>' +
          '<text class="d-mini" x="230" y="72" text-anchor="middle">D2</text>' +
          '<text class="d-mini" x="366" y="72" text-anchor="middle">Dn</text>' +
          xgbTree(94, 90) +
          xgbTree(230, 90) +
          xgbTree(366, 90) +
          '<text class="d-mini" x="162" y="94" text-anchor="middle">Residual</text>' +
          '<text class="d-mini" x="298" y="94" text-anchor="middle">Residual</text>' +
          '<rect x="58" y="154" width="72" height="24" rx="6" fill="#f8fafc" stroke="#0d9488" stroke-width="1.15"></rect>' +
          '<rect x="194" y="154" width="72" height="24" rx="6" fill="#f8fafc" stroke="#0d9488" stroke-width="1.15"></rect>' +
          '<rect x="330" y="154" width="72" height="24" rx="6" fill="#f8fafc" stroke="#0d9488" stroke-width="1.15"></rect>' +
          '<text class="d-label" x="94" y="169" text-anchor="middle">Prediction W1</text>' +
          '<text class="d-label" x="230" y="169" text-anchor="middle">Prediction W2</text>' +
          '<text class="d-label" x="366" y="169" text-anchor="middle">Prediction Wn</text>' +
          '<line class="d-line" x1="94" y1="178" x2="230" y2="194"></line>' +
          '<line class="d-line" x1="230" y1="178" x2="230" y2="194"></line>' +
          '<line class="d-line" x1="366" y1="178" x2="230" y2="194"></line>' +
          '<rect x="180" y="194" width="100" height="18" rx="5" fill="#f1f5f9" stroke="#94a3b8" stroke-width="1.1"></rect>' +
          '<text class="d-label" x="230" y="206" text-anchor="middle">Summation</text>' +
          '<line class="d-line" x1="230" y1="212" x2="230" y2="218" marker-end="' + arrowRef + '"></line>' +
          '<rect x="176" y="220" width="108" height="18" rx="5" fill="#f8fafc" stroke="#0f766e" stroke-width="1.15"></rect>' +
          '<text class="d-label" x="230" y="232" text-anchor="middle">Final Results</text>' +
        end
      );
    }

    if (id === "neural_network") {
      var inputNodes = makeLayerNodes(48, 44, 13, 12);
      var hidden1 = makeLayerNodes(146, 60, 8, 15);
      var hidden2 = makeLayerNodes(234, 60, 8, 15);
      var hidden3 = makeLayerNodes(318, 48, 10, 13);
      var outputAnchor = makeLayerNodes(374, 48, 10, 13);
      return (
        baseOpen +
          '<text class="d-title" x="48" y="24" text-anchor="middle">Input Layer in C</text>' +
          '<text class="d-title" x="146" y="24" text-anchor="middle">Hidden Layer in C^64</text>' +
          '<text class="d-title" x="234" y="24" text-anchor="middle">Hidden Layer in C^64</text>' +
          '<text class="d-title" x="318" y="24" text-anchor="middle">Hidden Layer in C^128</text>' +
          '<text class="d-title" x="388" y="24" text-anchor="middle">Output Layer in C</text>' +
          connectLayers(inputNodes, hidden1, "d-link") +
          connectLayers(hidden1, hidden2, "d-link") +
          connectLayers(hidden2, hidden3, "d-link") +
          connectLayers(hidden3, outputAnchor, "d-link") +
          drawNodes(inputNodes, 7, "#60a5fa", "#1d4ed8") +
          drawNodes(hidden1, 8, "#5ea2d3", "#1d4ed8") +
          drawNodes(hidden2, 8, "#5ea2d3", "#1d4ed8") +
          drawNodes(hidden3, 8, "#5ea2d3", "#1d4ed8") +
          '<rect x="374" y="40" width="20" height="132" fill="#8ea2b6" stroke="#334155" stroke-width="1.3"></rect>' +
          '<path class="d-line" d="M30 40 h-8 v132 h8"></path>' +
          '<path class="d-line" d="M430 40 h8 v132 h-8"></path>' +
          '<text class="d-mini" x="16" y="108" transform="rotate(-90 16,108)" text-anchor="middle">x [n x 16384]</text>' +
          '<text class="d-mini" x="442" y="108" transform="rotate(90 442,108)" text-anchor="middle">x^ [n x 16384]</text>' +
          '<text class="d-mini" x="400" y="56">x^1</text>' +
          '<text class="d-mini" x="400" y="72">x^2</text>' +
          '<text class="d-mini" x="400" y="88">x^3</text>' +
          '<text class="d-mini" x="400" y="104">x^4</text>' +
          '<text class="d-mini" x="400" y="120">...</text>' +
          '<text class="d-mini" x="400" y="168">x^n</text>' +
        end
      );
    }

    if (id === "linear") {
      return (
        baseOpen +
          '<text class="d-title" x="230" y="24" text-anchor="middle">Regression Family</text>' +
          '<line class="d-axis" x1="52" y1="200" x2="404" y2="200"></line><line class="d-axis" x1="52" y1="200" x2="52" y2="40"></line>' +
          '<line class="d-grid" x1="52" y1="168" x2="404" y2="168"></line>' +
          '<line class="d-grid" x1="52" y1="136" x2="404" y2="136"></line>' +
          '<line class="d-grid" x1="52" y1="104" x2="404" y2="104"></line>' +
          '<line class="d-grid" x1="52" y1="72" x2="404" y2="72"></line>' +
          '<g fill="#60a5fa" stroke="#1d4ed8" stroke-width="0.8">' +
            '<circle cx="78" cy="160" r="4"></circle><circle cx="108" cy="150" r="4"></circle><circle cx="138" cy="144" r="4"></circle><circle cx="166" cy="130" r="4"></circle><circle cx="202" cy="120" r="4"></circle><circle cx="236" cy="106" r="4"></circle><circle cx="272" cy="96" r="4"></circle><circle cx="304" cy="88" r="4"></circle>' +
          "</g>" +
          '<line class="d-line" x1="68" y1="166" x2="322" y2="84"></line>' +
          '<line class="d-line" x1="68" y1="172" x2="322" y2="98" stroke="#f97316" stroke-dasharray="5 3"></line>' +
          '<line class="d-line" x1="68" y1="156" x2="322" y2="74" stroke="#0ea5e9" stroke-dasharray="2 3"></line>' +
          '<text class="d-mini" x="332" y="86">OLS</text>' +
          '<text class="d-mini" x="332" y="100">Ridge and Lasso</text>' +
          '<text class="d-mini" x="332" y="74">Elastic Net</text>' +
          '<rect x="224" y="158" width="172" height="24" rx="6" fill="#ede9fe" stroke="#c4b5fd"></rect>' +
          '<text class="d-label" x="310" y="174" text-anchor="middle">y = b0 + b1x + b2x^2 + ...</text>' +
        end
      );
    }

    if (id === "knn") {
      return (
        baseOpen +
          '<text class="d-title" x="230" y="24" text-anchor="middle">KNN Distances</text>' +
          '<rect x="36" y="36" width="388" height="170" rx="10" fill="#ffffff" stroke="#cbd5e1"></rect>' +
          '<line class="d-grid" x1="36" y1="70" x2="424" y2="70"></line>' +
          '<line class="d-grid" x1="36" y1="104" x2="424" y2="104"></line>' +
          '<line class="d-grid" x1="36" y1="138" x2="424" y2="138"></line>' +
          '<line class="d-grid" x1="36" y1="172" x2="424" y2="172"></line>' +
          '<g fill="#60a5fa" stroke="#1d4ed8" stroke-width="0.8"><circle cx="90" cy="86" r="4.6"></circle><circle cx="120" cy="114" r="4.6"></circle><circle cx="152" cy="78" r="4.6"></circle><circle cx="182" cy="128" r="4.6"></circle><circle cx="214" cy="92" r="4.6"></circle></g>' +
          '<g fill="#22c55e" stroke="#15803d" stroke-width="0.8"><circle cx="268" cy="142" r="4.6"></circle><circle cx="298" cy="108" r="4.6"></circle><circle cx="330" cy="146" r="4.6"></circle><circle cx="360" cy="116" r="4.6"></circle></g>' +
          '<circle cx="230" cy="120" r="5.5" fill="#ef4444" stroke="#b91c1c" stroke-width="1"></circle>' +
          '<circle cx="230" cy="120" r="42" fill="none" stroke="#f97316" stroke-width="1.8"></circle>' +
          '<polygon points="230,76 274,120 230,164 186,120" fill="none" stroke="#1d4ed8" stroke-width="1.5" stroke-dasharray="4 3"></polygon>' +
          '<text class="d-mini" x="54" y="52">Euclidean: circular neighborhood</text><text class="d-mini" x="54" y="64">Manhattan: L1 diamond neighborhood</text>' +
        end
      );
    }

    if (id === "naive_bayes") {
      return (
        baseOpen +
          '<text class="d-title" x="230" y="24" text-anchor="middle">Naive Bayes Posterior Flow</text>' +
          '<rect x="44" y="50" width="96" height="22" rx="6" fill="#ccfbf1" stroke="#2dd4bf"></rect>' +
          '<rect x="44" y="90" width="96" height="22" rx="6" fill="#ccfbf1" stroke="#2dd4bf"></rect>' +
          '<rect x="44" y="130" width="96" height="22" rx="6" fill="#ccfbf1" stroke="#2dd4bf"></rect>' +
          '<text class="d-label" x="92" y="64" text-anchor="middle">Feature x1</text>' +
          '<text class="d-label" x="92" y="104" text-anchor="middle">Feature x2</text>' +
          '<text class="d-label" x="92" y="144" text-anchor="middle">Feature xn</text>' +
          '<line class="d-line" x1="140" y1="61" x2="240" y2="90"></line><line class="d-line" x1="140" y1="101" x2="240" y2="104"></line><line class="d-line" x1="140" y1="141" x2="240" y2="118"></line>' +
          '<rect x="240" y="82" width="116" height="48" rx="8" fill="#ecfeff" stroke="#67e8f9"></rect>' +
          '<text class="d-label" x="298" y="98" text-anchor="middle">Posterior</text>' +
          '<text class="d-title" x="298" y="114" text-anchor="middle">P(C|X) ~ P(C) * P(X|C)</text>' +
          '<line class="d-line" x1="356" y1="106" x2="406" y2="106" marker-end="' + arrowRef + '"></line>' +
          '<rect x="364" y="94" width="72" height="24" rx="6" fill="#dcfce7" stroke="#86efac"></rect>' +
          '<text class="d-label" x="400" y="110" text-anchor="middle">Class</text>' +
        end
      );
    }

    if (id === "sma" || id === "sgma") {
      var gateBand = id === "sgma"
        ? '<rect x="68" y="100" width="330" height="34" fill="rgba(14,116,110,0.08)" stroke="none"></rect>'
        : "";
      return (
        baseOpen +
          '<text class="d-title" x="230" y="24" text-anchor="middle">' + (id === "sgma" ? "Stability-gated Moving Average" : "Simple Moving Average") + '</text>' +
          '<line class="d-axis" x1="64" y1="204" x2="408" y2="204"></line><line class="d-axis" x1="64" y1="204" x2="64" y2="44"></line>' +
          '<line class="d-grid" x1="64" y1="172" x2="408" y2="172"></line>' +
          '<line class="d-grid" x1="64" y1="140" x2="408" y2="140"></line>' +
          '<line class="d-grid" x1="64" y1="108" x2="408" y2="108"></line>' +
          gateBand +
          '<polyline points="72,174 106,152 140,158 174,126 208,136 242,104 276,112 310,92 344,104 378,86" fill="none" stroke="#94a3b8" stroke-width="2"></polyline>' +
          '<polyline points="72,162 106,154 140,146 174,138 208,130 242,124 276,118 310,112 344,106 378,100" fill="none" stroke="#0ea5e9" stroke-width="2.5"></polyline>' +
          '<polyline points="72,168 106,160 140,152 174,144 208,138 242,132 276,126 310,122 344,116 378,112" fill="none" stroke="#0f766e" stroke-width="2.1" stroke-dasharray="4 3"></polyline>' +
          '<text class="d-mini" x="76" y="58">' + (id === "sgma" ? "SGMA trend with stability gate band" : "SMA trend windows (short vs long)") + '</text>' +
        end
      );
    }

    return (
      baseOpen +
        '<rect x="14" y="12" width="432" height="220" rx="10" fill="#f8fafc" stroke="#dbe3ef"></rect>' +
        '<text x="230" y="126" text-anchor="middle" font-size="12" fill="#334155">Model visual unavailable</text>' +
      end
    );
  }

  function renderModelVisualLab(cfg, history) {
    if (!els.modelLabGrid) return;
    var list = Array.isArray(history) ? history : getHistory(cfg);
    var style = normalizeAccuracyStyle(cfg.accuracyStyle);
    var groups = MODEL_VISUAL_GROUPS.slice();
    if (groups.length < 1) {
      els.modelLabGrid.innerHTML = '<div class="text-muted py-3">No model groups configured.</div>';
      return;
    }

    var html = [];
    var totalVariants = 0;
    for (var g = 0; g < groups.length; g++) {
      var group = groups[g] || {};
      var variants = Array.isArray(group.variants) ? group.variants : [];
      totalVariants += variants.length;

      var best = null;
      var rowsHtml = [];
      for (var v = 0; v < variants.length; v++) {
        var row = resolveVariantPrediction(cfg, list, style, variants[v]);
        var hr = row.hitRate;
        if (!best || ((hr != null ? hr : -1) > (best.hitRate != null ? best.hitRate : -1))) {
          best = row;
        }

        var comboHtml = row.prediction && Array.isArray(row.prediction.combo) && row.prediction.combo.length === cfg.by
          ? comboToChipHtml(row.prediction.combo)
          : '<span class="text-muted">--</span>';
        var formulaText = String(variants[v].formula || algorithmBaseFormulaText(row.algo));
        var accText = hr == null ? "--" : (Math.round(hr * 1000) / 10).toFixed(1) + "%";
        rowsHtml.push(
          '<div class="nn-model-row">' +
            '<div class="nn-model-variant">' +
              '<div class="name">' + escapeHtml(variants[v].label || ("Variant " + (v + 1))) + "</div>" +
              '<div class="formula">' + escapeHtml(formulaText) + "</div>" +
            "</div>" +
            '<div class="nn-model-accuracy">' + escapeHtml(accText) + "</div>" +
            '<div class="nn-model-prediction">' + comboHtml + "</div>" +
          "</div>"
        );
      }

      var selected = normalizeAlgorithm(cfg.algorithm) === normalizeAlgorithm(group.id || "");
      var selectedBadge = selected ? " <small>(Selected)</small>" : "";
      var bestText = best && best.variant
        ? ("Best sub-model: " + (best.variant.label || "") + " | hit rate " + percentageText(best.hitRate))
        : "No sub-model result yet.";

      html.push(
        '<div class="nn-model-card' + (selected ? " is-selected" : "") + '">' +
          '<div class="nn-model-head">' +
            '<div class="nn-model-title">' + escapeHtml(group.title || "Model") + selectedBadge + "</div>" +
            '<div class="nn-model-subtitle">' + escapeHtml(group.subtitle || "") + "</div>" +
          "</div>" +
          '<div class="nn-model-visual">' + modelVisualSvg(group.visual || group.id) + "</div>" +
          '<div class="nn-model-table">' + rowsHtml.join("") + "</div>" +
          '<div class="nn-model-foot">' + escapeHtml(bestText) + "</div>" +
        "</div>"
      );
    }

    els.modelLabGrid.innerHTML = html.join("");
    if (els.modelLabMeta) {
      els.modelLabMeta.textContent =
        groups.length + " model groups | " + totalVariants + " sub-models | style " + accuracyStyleLabel(style) + " | history entries: " + list.length;
    }
  }

  function combosEqual(a, b) {
    if (!Array.isArray(a) || !Array.isArray(b)) return false;
    if (a.length !== b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (parseInt(a[i], 10) !== parseInt(b[i], 10)) return false;
    }
    return true;
  }

  function comboHitCount(predicted, actual) {
    if (!Array.isArray(predicted) || !Array.isArray(actual)) return 0;
    var map = Object.create(null);
    for (var i = 0; i < actual.length; i++) {
      map[String(actual[i])] = true;
    }
    var hits = 0;
    for (var j = 0; j < predicted.length; j++) {
      if (map[String(predicted[j])]) hits++;
    }
    return hits;
  }

  function comboDistance(predicted, actual) {
    if (!Array.isArray(predicted) || !Array.isArray(actual)) return Number.POSITIVE_INFINITY;
    if (predicted.length !== actual.length) return Number.POSITIVE_INFINITY;
    var p = predicted.slice().sort(function (a, b) { return a - b; });
    var a2 = actual.slice().sort(function (a, b) { return a - b; });
    var total = 0;
    for (var i = 0; i < p.length; i++) {
      total += Math.abs(parseInt(p[i], 10) - parseInt(a2[i], 10));
    }
    return total;
  }

  function buildActualResultStudy(cfg, historyBefore, style, actualCombo) {
    var styleKey = normalizeAccuracyStyle(style || cfg.accuracyStyle);
    var actual = Array.isArray(actualCombo) ? actualCombo.slice() : [];
    var rows = [];

    for (var i = 0; i < COMPARE_ALGOS.length; i++) {
      var algo = normalizeAlgorithm(COMPARE_ALGOS[i]);
      var saved = getSavedAlgorithmResult(cfg, historyBefore, styleKey, algo);
      var prediction = saved
        ? {
            combo: Array.isArray(saved.combo) ? saved.combo.slice() : [],
            topFive: Array.isArray(saved.topFive) ? saved.topFive.slice() : []
          }
        : buildPrediction(cfg, historyBefore, algo, styleKey);
      var combo = Array.isArray(prediction.combo) ? prediction.combo.slice() : [];
      if (combo.length !== cfg.by) continue;

      var hits = comboHitCount(combo, actual);
      var dist = comboDistance(combo, actual);
      var exact = combosEqual(combo.slice().sort(function (a, b) { return a - b; }), actual.slice().sort(function (a, b) { return a - b; }));

      rows.push({
        algorithm: algo,
        label: algorithmLabel(algo),
        combo: combo,
        hits: hits,
        distance: dist,
        exact: exact,
        nearest: false
      });
    }

    rows.sort(function (a, b) {
      if (a.exact !== b.exact) return a.exact ? -1 : 1;
      if (a.hits !== b.hits) return b.hits - a.hits;
      if (a.distance !== b.distance) return a.distance - b.distance;
      if (a.label < b.label) return -1;
      if (a.label > b.label) return 1;
      return 0;
    });

    if (rows.length > 0) {
      rows[0].nearest = true;
    }

    var correctCount = 0;
    for (var c = 0; c < rows.length; c++) {
      if (rows[c].exact) correctCount++;
    }

    return {
      key: cfg.storeKey,
      by: cfg.by,
      actualCombo: actual,
      analyzedAt: Date.now(),
      style: styleKey,
      rows: rows,
      correctCount: correctCount,
      nearestAlgorithm: rows.length > 0 ? rows[0].algorithm : ""
    };
  }

  function renderActualStudy(cfg) {
    if (!els.actualStudyBody) return;
    var study = state.lastActualStudy;
    var validStudy =
      !!study &&
      study.key === cfg.storeKey &&
      Array.isArray(study.rows) &&
      study.rows.length > 0 &&
      Array.isArray(study.actualCombo);

    if (!validStudy) {
      els.actualStudyBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No actual result study yet.</td></tr>';
      if (els.actualStudyMeta) {
        els.actualStudyMeta.textContent = "Add a manual result to evaluate all algorithms.";
      }
      return;
    }

    var rows = study.rows.slice();
    var html = [];
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      var verdict = row.exact
        ? '<span class="badge bg-success-subtle text-success border border-success-subtle">Correct</span>'
        : (row.nearest
            ? '<span class="badge bg-primary-subtle text-primary border border-primary-subtle">Nearest</span>'
            : '<span class="badge bg-light text-muted border">Miss</span>');
      html.push(
        "<tr>" +
          "<td>" + escapeHtml(row.label) + "</td>" +
          "<td>" + comboToChipHtml(row.combo) + "</td>" +
          "<td>" + escapeHtml(String(row.hits) + " / " + String(cfg.by)) + "</td>" +
          "<td>" + escapeHtml(String(row.distance)) + "</td>" +
          "<td>" + verdict + "</td>" +
        "</tr>"
      );
    }
    els.actualStudyBody.innerHTML = html.join("");

    if (els.actualStudyMeta) {
      var nearestLabel = rows[0] ? rows[0].label : "--";
      els.actualStudyMeta.textContent =
        "Actual: " + study.actualCombo.join(" - ") +
        " | Correct algorithms: " + study.correctCount +
        " | Nearest: " + nearestLabel +
        " | Evaluated at " + formatDateTime(study.analyzedAt);
    }
  }

  function historyMatchesRange(entry, fromTs, toTs) {
    var ts = parseTimestampLoose(entry && entry.ts, 0);
    if (fromTs != null && ts < fromTs) return false;
    if (toTs != null && ts > toTs) return false;
    return true;
  }

  function renderHistoryHead(cfg) {
    if (!els.historyHead) return;
    var by = (cfg && isFinite(cfg.by)) ? parseInt(cfg.by, 10) : 3;
    if (!isFinite(by) || by < 1) by = 3;
    var html = ['<tr><th style="width: 80px;">#</th>'];
    for (var i = 1; i <= by; i++) {
      html.push("<th>Value " + i + "</th>");
    }
    html.push('<th style="width: 190px;">Generated At</th></tr>');
    els.historyHead.innerHTML = html.join("");
  }

  function renderGenerated(rows, cfg) {
    if (!els.generatedBody) return;

    if (!Array.isArray(rows) || rows.length === 0) {
      els.generatedBody.innerHTML = '<tr><td colspan="2" class="text-center text-muted py-4">Generate a table to view combinations.</td></tr>';
      if (els.generatedMeta) els.generatedMeta.textContent = "No table generated yet.";
      if (els.rowsGenerated) els.rowsGenerated.textContent = "0";
      return;
    }

    var html = [];
    for (var i = 0; i < rows.length; i++) {
      html.push(
        "<tr>" +
          "<td>" + (i + 1) + "</td>" +
          "<td>" + comboToChipHtml(rows[i]) + "</td>" +
        "</tr>"
      );
    }
    els.generatedBody.innerHTML = html.join("");

    if (els.generatedMeta) {
      var stamp = state.generatedAt > 0 ? formatDateTime(state.generatedAt) : "--";
      els.generatedMeta.textContent = cfg.key + " | " + rows.length + " rows | generated at " + stamp;
    }
    if (els.rowsGenerated) els.rowsGenerated.textContent = String(rows.length);
  }

  function renderHistory(cfg) {
    var history = getHistory(cfg);
    renderHistoryHead(cfg);
    var ordered = history.slice().reverse();
    var bounds = normalizeRangeBounds(state.historyRangeFromTs, state.historyRangeToTs);
    var filtered = [];
    for (var i = 0; i < ordered.length; i++) {
      if (historyMatchesRange(ordered[i], bounds.fromTs, bounds.toTs)) {
        filtered.push(ordered[i]);
      }
    }

    var pageSize = state.historyPageSize > 0 ? state.historyPageSize : Math.max(1, total);
    var total = filtered.length;
    var totalPages = Math.max(1, Math.ceil(total / pageSize));
    if (state.historyPage > totalPages) state.historyPage = totalPages;
    if (state.historyPage < 1) state.historyPage = 1;
    var start = (state.historyPage - 1) * pageSize;
    var pageRows = filtered.slice(start, start + pageSize);

    if (els.historyCount) els.historyCount.textContent = String(history.length);
    if (els.historyMeta) {
      els.historyMeta.textContent = total > 0
        ? ("Showing " + (start + 1) + "-" + (start + pageRows.length) + " of " + total + " filtered entries (" + history.length + " total), latest first")
        : "No history yet.";
    }
    if (els.historyPageInfo) {
      els.historyPageInfo.textContent = "Page " + state.historyPage + " / " + totalPages;
    }
    if (els.historyPrevBtn) els.historyPrevBtn.disabled = state.historyPage <= 1;
    if (els.historyNextBtn) els.historyNextBtn.disabled = state.historyPage >= totalPages;

    if (!els.historyBody) return history;

    if (total === 0) {
      var hasRange = bounds.fromTs != null || bounds.toTs != null;
      var emptyMsg = (history.length > 0 && hasRange)
        ? "No history entries match the current timestamp range filter."
        : "No history for this table key yet.";
      var emptyCols = (cfg && isFinite(cfg.by) ? parseInt(cfg.by, 10) : 3) + 2;
      els.historyBody.innerHTML = '<tr><td colspan="' + emptyCols + '" class="text-center text-muted py-4">' + escapeHtml(emptyMsg) + '</td></tr>';
      return history;
    }

    var html = [];
    for (var r = 0; r < pageRows.length; r++) {
      var entry = pageRows[r];
      var combo = Array.isArray(entry.combo) ? entry.combo : [];
      var valueCells = [];
      for (var c = 0; c < cfg.by; c++) {
        var v = (c < combo.length) ? combo[c] : "--";
        valueCells.push("<td>" + escapeHtml(v) + "</td>");
      }
      html.push(
        "<tr>" +
          "<td>" + (start + r + 1) + "</td>" +
          valueCells.join("") +
          "<td>" + escapeHtml(formatDateTime(entry.ts)) + "</td>" +
        "</tr>"
      );
    }
    els.historyBody.innerHTML = html.join("");
    return history;
  }

  function parseManualDraft(cfg) {
    if (!els.manualCombo) {
      return { hasInput: false, valid: false, combo: null, error: "" };
    }

    var raw = String(els.manualCombo.value == null ? "" : els.manualCombo.value).trim();
    if (raw === "") {
      return { hasInput: false, valid: false, combo: null, error: "" };
    }

    var combo = sanitizeCombo(parseComboFromString(raw), cfg);
    if (!combo) {
      var needText = cfg.allowRepeat ? "values" : "unique values";
      return {
        hasInput: true,
        valid: false,
        combo: null,
        error: "Need exactly " + cfg.by + " " + needText + " in range " + cfg.min + "-" + cfg.max + "."
      };
    }

    return { hasInput: true, valid: true, combo: combo, error: "" };
  }

  function renderManualHint(cfg, draft) {
    if (!els.manualHint) return;
    if (!draft.hasInput) {
      els.manualHint.classList.remove("text-success");
      els.manualHint.classList.remove("text-danger");
      var needText = cfg.allowRepeat ? "numbers" : "unique numbers";
      els.manualHint.innerHTML =
        'Enter exactly <code>' + cfg.by + "</code> " + needText + " within <code>" + cfg.min + "-" + cfg.max + "</code>. Prediction updates live while typing.";
      return;
    }

    if (!draft.valid) {
      els.manualHint.classList.remove("text-success");
      els.manualHint.classList.add("text-danger");
      els.manualHint.textContent = draft.error;
      return;
    }

    els.manualHint.classList.remove("text-danger");
    els.manualHint.classList.add("text-success");
    els.manualHint.textContent = "Valid combination: " + draft.combo.join(" - ") + ". Preview included in live prediction.";
  }

  function setManualDraftForConfig(cfg) {
    var draft = parseManualDraft(cfg);
    renderManualHint(cfg, draft);

    if (!draft.valid) {
      state.manualDraft = null;
      return null;
    }

    state.manualDraft = {
      key: cfg.key,
      combo: draft.combo.slice()
    };
    return state.manualDraft.combo.slice();
  }

  function effectiveHistoryWithManualDraft(cfg, history) {
    var base = Array.isArray(history) ? history.slice() : [];
    if (
      state.manualDraft &&
      state.manualDraft.key === cfg.key &&
      Array.isArray(state.manualDraft.combo) &&
      state.manualDraft.combo.length === cfg.by
    ) {
      base.push({
        ts: 1,
        combo: state.manualDraft.combo.slice()
      });
    }
    return base;
  }

  function renderPrediction(cfg, history) {
    var list = Array.isArray(history) ? history : getHistory(cfg);
    var algo = normalizeAlgorithm(cfg.algorithm);
    var style = normalizeAccuracyStyle(cfg.accuracyStyle);
    var saved = getSavedAlgorithmResult(cfg, list, style, algo);
    var prediction = saved
      ? {
          combo: saved.combo.slice(),
          topFive: Array.isArray(saved.topFive) ? saved.topFive.slice() : [],
          profile: null
        }
      : buildPrediction(cfg, list, algo, style);
    var profile = prediction.profile || null;
    var top = prediction.topFive;

    if (els.topPredictions) {
      if (!top || top.length < 5) {
        els.topPredictions.innerHTML = '<div class="text-muted small">Not enough values to compute top 5.</div>';
      } else {
        var topScore = top[0].score > 0 ? top[0].score : 1;
        var topHtml = [];
        for (var i = 0; i < top.length; i++) {
          var item = top[i];
          var indexPct = Math.round((item.score / topScore) * 100);
          topHtml.push(
            '<div class="nn-predict-item">' +
              '<span class="nn-chip nn-chip-predict">' + escapeHtml(item.number) + '</span>' +
              '<span class="nn-predict-score">Accuracy index: ' + indexPct + '%</span>' +
            '</div>'
          );
        }
        els.topPredictions.innerHTML = topHtml.join("");
      }
    }

    if (els.nextCombo) {
      if (!prediction.combo || prediction.combo.length !== cfg.by) {
        els.nextCombo.innerHTML = "--";
      } else {
        els.nextCombo.innerHTML = comboToChipHtml(prediction.combo);
      }
    }

    var hitRate = saved ? saved.hitRate : estimateHitRate(cfg, list, algo, style, profile);
    if (els.hitRate) {
      els.hitRate.textContent = (hitRate == null)
        ? ("Baseline hit rate (" + algorithmLabel(algo) + ", " + accuracyStyleLabel(style) + "): --")
        : ("Baseline hit rate (" + algorithmLabel(algo) + ", " + accuracyStyleLabel(style) + "): " + Math.round(hitRate * 100) + "%");
    }

    if (els.modelMeta) {
      var tuneText = "default profile";
      if (saved) {
        tuneText = "loaded from saved snapshot";
      } else if (profile && profile.tuned) {
        tuneText = "tuned on " + profile.sampleCount + " sample windows";
      }
      els.modelMeta.textContent =
        "Model: " + algorithmLabel(algo) +
        " | " + algorithmDetail(algo) +
        " | style: " + accuracyStyleLabel(style) +
        " (" + accuracyStyleDetail(style) + ")" +
        " | " + tuneText;
    }

    if (els.predictNote) {
      var manualActive =
        state.manualDraft &&
        state.manualDraft.key === cfg.key &&
        Array.isArray(state.manualDraft.combo) &&
        state.manualDraft.combo.length === cfg.by;

      if (manualActive) {
        els.predictNote.textContent =
          "Live preview includes your manual combination before saving it to history.";
      } else if (list.length < 8) {
        els.predictNote.textContent = "Prediction uses limited history. Add more entries to improve confidence.";
      } else {
        els.predictNote.textContent =
          "Prediction uses " + algorithmLabel(algo) +
          " with a " + accuracyStyleLabel(style) +
          " profile calibrated against this table key history. All algorithm outputs are saved after recalculation.";
      }
    }
  }

  function refreshStatusBoxes(cfg) {
    if (els.tableKey) {
      var repeatText = cfg.allowRepeat ? " | repeats on" : " | repeats off";
      els.tableKey.textContent = cfg.key + repeatText;
    }
    if (els.algoActive) els.algoActive.textContent = algorithmLabel(cfg.algorithm);
    if (els.storedKeys) els.storedKeys.textContent = String(getStoredKeyCount());
  }

  function renderForConfig(cfg) {
    refreshStatusBoxes(cfg);
    refreshTableFilterOptions(cfg);
    setManualDraftForConfig(cfg);
    ensurePasteGridForConfig(cfg);
    renderActualStudy(cfg);

    if (state.currentConfigKey !== cfg.key) {
      renderGenerated([], cfg);
    }

    var history = renderHistory(cfg);
    var effectiveHistory = effectiveHistoryWithManualDraft(cfg, history);
    renderPrediction(cfg, effectiveHistory);
    renderAlgorithmComparison(cfg, effectiveHistory);
    renderModelVisualLab(cfg, effectiveHistory);
  }

  function persistEmptySnapshotsForConfig(cfg, onDone) {
    var done = (typeof onDone === "function") ? onDone : function () {};
    var style = normalizeAccuracyStyle(cfg.accuracyStyle);
    clearSnapshotBucket(cfg, style);
    saveSnapshotsRemote(cfg, style, "0", null, function () {
      done();
    });
  }

  function recalcAndPersistForConfig(cfg, opts, onDone) {
    var done = (typeof onDone === "function") ? onDone : function () {};
    var style = normalizeAccuracyStyle(cfg.accuracyStyle);
    var history = getHistory(cfg);
    if (!Array.isArray(history) || history.length < 1) {
      persistEmptySnapshotsForConfig(cfg, done);
      return;
    }

    recalcAllAlgorithms(cfg, history, style, opts || {}, function (bucket) {
      done(bucket);
    });
  }

  function collectRepeatSizesFromChecks(allowedSizes) {
    var allowedMap = Object.create(null);
    for (var i = 0; i < allowedSizes.length; i++) {
      allowedMap[String(allowedSizes[i])] = true;
    }
    if (!els.repeatSizeChecks) return [];
    var nodes = els.repeatSizeChecks.querySelectorAll('input[type="checkbox"]');
    var out = [];
    for (var n = 0; n < nodes.length; n++) {
      if (!nodes[n].checked) continue;
      var v = normalizeTableSizeValue(nodes[n].value);
      if (v == null) continue;
      if (!allowedMap[String(v)]) continue;
      out.push(v);
    }
    out.sort(function (a, b) { return a - b; });
    return out;
  }

  function savePolicyRemote(settings, onDone) {
    var done = (typeof onDone === "function") ? onDone : function () {};
    if (!canUseRemoteStoreApi() || STORE_API_CSRF === "") {
      done(false, null);
      return;
    }
    var body = JSON.stringify({
      action: "save_settings",
      csrf_token: STORE_API_CSRF,
      settings: {
        allowed_table_sizes: settings.allowedTableSizes,
        repeatable_sizes: settings.repeatableSizes
      }
    });

    fetch(STORE_API_URL, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json"
      },
      body: body
    })
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (data) {
        if (!data || data.status !== "ok") {
          throw new Error((data && data.message) ? data.message : "Unable to save policy.");
        }
        var sanitized = sanitizeSettingsObject(data.settings || settings);
        done(true, sanitized);
      })
      .catch(function () {
        done(false, null);
      });
  }

  function showConfigErrors(errors) {
    var msg = Array.isArray(errors) ? errors.join("\n") : "Invalid configuration.";
    setStatus("Invalid setup");
    window.alert(msg);
  }

  function buildConfigOrFail() {
    var norm = normalizeConfig();
    var cfg = finalizeConfig(norm.cfg);
    if (!norm.ok) {
      showConfigErrors(norm.errors);
      return null;
    }
    return cfg;
  }

  function onGenerate() {
    var cfg = buildConfigOrFail();
    if (!cfg) return;

    state.currentRows = [];
    state.currentConfigKey = cfg.key;
    state.generatedAt = Date.now();

    renderGenerated([], cfg);
    recalcAndPersistForConfig(cfg, { showModal: false }, function () {
      renderForConfig(cfg);
      setStatus("Analysis refreshed (manual/import history only)");
    });
  }

  function onPredict() {
    var cfg = buildConfigOrFail();
    if (!cfg) return;

    var draft = parseManualDraft(cfg);
    if (draft && draft.valid) {
      renderForConfig(cfg);
      setStatus("Prediction updated");
      return;
    }

    recalcAndPersistForConfig(cfg, { showModal: false }, function () {
      renderForConfig(cfg);
      setStatus("Prediction updated");
    });
  }

  function onExportJson() {
    var cfg = buildConfigOrFail();
    if (!cfg) return;
    exportHistoryAsJson(cfg);
  }

  function onExportCsv() {
    var cfg = buildConfigOrFail();
    if (!cfg) return;
    exportHistoryAsCsv(cfg);
  }

  function onExportAllJson() {
    exportAllHistoryAsJson();
    var norm = normalizeConfig();
    refreshStatusBoxes(finalizeConfig(norm.cfg));
  }

  function onImportTrigger() {
    var cfg = buildConfigOrFail();
    if (!cfg) return;
    if (!els.importFile) {
      window.alert("Import file input is not available.");
      return;
    }
    els.importFile.value = "";
    els.importFile.click();
  }

  function onImportAllTrigger() {
    if (!els.importAllFile) {
      window.alert("Import-all file input is not available.");
      return;
    }
    els.importAllFile.value = "";
    els.importAllFile.click();
  }

  function onImportFileChange() {
    if (!els.importFile || !els.importFile.files || els.importFile.files.length < 1) return;
    var cfg = buildConfigOrFail();
    if (!cfg) {
      els.importFile.value = "";
      return;
    }

    var file = els.importFile.files[0];
    if (!file) return;
    var name = String(file.name || "").toLowerCase();
    var fileType = String(file.type || "").toLowerCase();
    var isXlsx = name.endsWith(".xlsx") || fileType === "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
    var isCsv = name.endsWith(".csv") || fileType === "text/csv" || fileType === "application/csv";

    var reader = new FileReader();
    reader.onload = function () {
      var parsed = null;

      if (isXlsx) {
        parsed = parseXlsxImportEntries(reader.result, cfg);
      } else if (isCsv) {
        var csvText = String(reader.result == null ? "" : reader.result);
        parsed = parseCsvImportEntries(csvText, cfg);
      } else {
        var jsonText = String(reader.result == null ? "" : reader.result);
        parsed = parseJsonImportEntries(jsonText, cfg);
      }

      if (!parsed || !Array.isArray(parsed.cleaned)) {
        window.alert("Unable to parse import file.");
        setStatus("Import failed");
        els.importFile.value = "";
        return;
      }

      if (parsed.error) {
        window.alert(parsed.error);
        setStatus("Import failed");
        els.importFile.value = "";
        return;
      }

      var imported = importEntriesIntoHistory(cfg, parsed.cleaned);
      if (!imported) {
        els.importFile.value = "";
        return;
      }
      state.historyPage = 1;
      recalcAndPersistForConfig(cfg, { showModal: false }, function () {
        renderForConfig(cfg);
        setStatus("History imported");
      });

      if (parsed.skipped > 0) {
        window.alert("Import completed with " + parsed.skipped + " skipped row(s) due to invalid combo format/range.");
      }

      els.importFile.value = "";
    };
    reader.onerror = function () {
      window.alert("Unable to read the selected file.");
      setStatus("Import failed");
      els.importFile.value = "";
    };
    if (isXlsx) {
      reader.readAsArrayBuffer(file);
    } else {
      reader.readAsText(file);
    }
  }

  function onImportAllFileChange() {
    if (!els.importAllFile || !els.importAllFile.files || els.importAllFile.files.length < 1) return;

    var file = els.importAllFile.files[0];
    if (!file) return;

    var reader = new FileReader();
    reader.onload = function () {
      var text = String(reader.result == null ? "" : reader.result);
      var parsed = parseAllKeysJson(text);
      if (parsed.error || !parsed.summary) {
        window.alert(parsed.error || "Unable to parse all-keys import file.");
        setStatus("All-keys import failed");
        els.importAllFile.value = "";
        return;
      }

      var ok = importAllStore(parsed.summary);
      if (!ok) {
        setStatus("All-keys import failed");
        els.importAllFile.value = "";
        return;
      }

      var norm = normalizeConfig();
      var cfg = finalizeConfig(norm.cfg);
      state.historyPage = 1;
      renderForConfig(cfg);
      setStatus("All-keys history imported");

      if (parsed.summary.skippedKeys > 0 || parsed.summary.skippedRows > 0) {
        window.alert(
          "Import completed with skipped data:\n" +
          "- Skipped keys: " + parsed.summary.skippedKeys + "\n" +
          "- Skipped rows: " + parsed.summary.skippedRows
        );
      }

      els.importAllFile.value = "";
    };
    reader.onerror = function () {
      window.alert("Unable to read the selected all-keys file.");
      setStatus("All-keys import failed");
      els.importAllFile.value = "";
    };
    reader.readAsText(file);
  }

  function onImportGrid() {
    var cfg = buildConfigOrFail();
    if (!cfg) return;
    ensurePasteGridForConfig(cfg);

    var parsed = parseGridEntries(cfg);
    if (parsed.error) {
      window.alert(parsed.error);
      setStatus("Grid import failed");
      return;
    }
    if (!Array.isArray(parsed.cleaned) || parsed.cleaned.length < 1) {
      window.alert("No valid rows were detected in the spreadsheet grid.");
      setStatus("Grid import failed");
      return;
    }

    var imported = importEntriesIntoHistory(cfg, parsed.cleaned);
    if (!imported) return;
    state.historyPage = 1;

    recalcAndPersistForConfig(cfg, {
      showModal: true,
      modalTitle: "Recalculating All Algorithms",
      modalText: "Spreadsheet rows imported. Recomputing algorithm snapshots..."
    }, function () {
      renderForConfig(cfg);
      setStatus("Spreadsheet grid imported");
      if (parsed.skipped > 0) {
        window.alert("Grid import completed with " + parsed.skipped + " skipped row(s).");
      }
    });
  }

  function onClearGrid() {
    clearPasteGridCells();
    setStatus("Spreadsheet grid cleared");
  }

  function onBuildPasteGrid() {
    var cfg = buildConfigOrFail();
    if (!cfg) return;
    buildPasteGrid(cfg, true);
    setStatus("Spreadsheet grid rebuilt");
  }

  function onPasteGridKeydown(ev) {
    if (!ev) return;
    if ((ev.ctrlKey || ev.metaKey) && String(ev.key).toLowerCase() === "enter") {
      ev.preventDefault();
      onImportGrid();
    }
  }

  function onManualInputChange() {
    var norm = normalizeConfig();
    var cfg = finalizeConfig(norm.cfg);
    renderForConfig(cfg);
  }

  function onManualAdd() {
    var cfg = buildConfigOrFail();
    if (!cfg) return;

    var draft = parseManualDraft(cfg);
    renderManualHint(cfg, draft);
    if (!draft.valid || !Array.isArray(draft.combo)) {
      window.alert("Manual combination is invalid for " + cfg.key + ".");
      return;
    }

    var beforeHistory = getHistory(cfg);
    var style = normalizeAccuracyStyle(cfg.accuracyStyle);
    state.lastActualStudy = buildActualResultStudy(cfg, beforeHistory, style, draft.combo.slice());

    appendHistory(cfg, [draft.combo.slice()]);
    state.currentRows = [draft.combo.slice()];
    state.currentConfigKey = cfg.key;
    state.generatedAt = Date.now();
    state.manualDraft = null;
    state.historyPage = 1;

    if (els.manualCombo) {
      els.manualCombo.value = "";
    }

    renderGenerated(state.currentRows, cfg);
    recalcAndPersistForConfig(cfg, {
      showModal: true,
      modalTitle: "Recalculating All Algorithms",
      modalText: "Your new entry was saved. Recomputing algorithm snapshots..."
    }, function () {
      renderForConfig(cfg);
      var study = state.lastActualStudy;
      var nearestText = (study && study.rows && study.rows[0]) ? study.rows[0].label : "--";
      var correctText = (study && typeof study.correctCount === "number") ? study.correctCount : 0;
      setStatus("Actual result saved. Correct algorithms: " + correctText + " | nearest: " + nearestText + " | recalibrated and predicted next.");
    });
  }

  function onManualClear() {
    if (els.manualCombo) {
      els.manualCombo.value = "";
    }
    state.manualDraft = null;
    var norm = normalizeConfig();
    var cfg = finalizeConfig(norm.cfg);
    renderForConfig(cfg);
    setStatus("Manual input cleared");
  }

  function onManualComboKeydown(ev) {
    if (!ev) return;
    if (ev.key === "Enter") {
      ev.preventDefault();
      onManualAdd();
    }
  }

  function onClearHistory() {
    var cfg = buildConfigOrFail();
    if (!cfg) return;

    var ok = window.confirm("Clear history for " + cfg.key + "?");
    if (!ok) return;

    clearHistory(cfg);
    state.historyPage = 1;
    if (state.lastActualStudy && state.lastActualStudy.key === cfg.storeKey) {
      state.lastActualStudy = null;
    }

    if (state.currentConfigKey === cfg.key) {
      state.currentRows = [];
      state.currentConfigKey = "";
      state.generatedAt = 0;
    }

    renderGenerated([], cfg);
    persistEmptySnapshotsForConfig(cfg, function () {
      renderForConfig(cfg);
      setStatus("History cleared");
    });
  }

  function onSavePolicy() {
    if (!IS_SUPERADMIN) return;
    if (!els.allowedSizesInput) return;

    var allowed = normalizeSizeList(els.allowedSizesInput.value);
    if (allowed.length < 1) {
      window.alert("Allowed table sizes must include at least one value from 2 to 20.");
      return;
    }
    var repeat = collectRepeatSizesFromChecks(allowed);
    var settings = sanitizeSettingsObject({
      allowedTableSizes: allowed,
      repeatableSizes: repeat
    });

    if (els.policyMeta) els.policyMeta.textContent = "Saving policy...";
    savePolicyRemote(settings, function (ok, savedSettings) {
      if (!ok || !savedSettings) {
        if (els.policyMeta) els.policyMeta.textContent = "Unable to save policy right now.";
        setStatus("Policy save failed");
        return;
      }

      state.settings = sanitizeSettingsObject(savedSettings);
      renderPolicyControls();
      var norm = normalizeConfig();
      var cfg = finalizeConfig(norm.cfg);
      rememberProfileFromConfig(cfg);
      renderTableWizardButtons(cfg.by);
      renderForConfig(cfg);
      if (els.policyMeta) {
        els.policyMeta.textContent = "Policy saved. Active sizes: " + state.settings.allowedTableSizes.join(", ");
      }
      setStatus("Table policy saved");
    });
  }

  function onAllowedSizesInputChange() {
    if (!IS_SUPERADMIN) return;
    if (!els.allowedSizesInput) return;
    var allowed = normalizeSizeList(els.allowedSizesInput.value);
    if (allowed.length < 1) return;
    var currentRepeat = collectRepeatSizesFromChecks(activeAllowedSizes());
    renderRepeatSizeCheckboxes(allowed, currentRepeat);
  }

  function onApplyTableFilter() {
    if (!els.tableFilterKey) return;
    var fromParsed = parseFilterRangeValue(els.tableFilterFromTs ? els.tableFilterFromTs.value : "");
    var toParsed = parseFilterRangeValue(els.tableFilterToTs ? els.tableFilterToTs.value : "");
    if (fromParsed !== null && !isFinite(fromParsed)) {
      window.alert("Invalid From Timestamp. Use numeric timestamp (ms) or a valid date string.");
      return;
    }
    if (toParsed !== null && !isFinite(toParsed)) {
      window.alert("Invalid To Timestamp. Use numeric timestamp (ms) or a valid date string.");
      return;
    }
    var bounds = normalizeRangeBounds(fromParsed, toParsed);
    state.historyRangeFromTs = bounds.fromTs;
    state.historyRangeToTs = bounds.toTs;
    state.historyPage = 1;

    var selectedKey = String(els.tableFilterKey.value || "").trim();

    if (selectedKey === "") {
      state.tableFilterStoreKey = "";
      var baseNorm = normalizeConfig();
      var baseCfg = finalizeConfig(baseNorm.cfg);
      rememberProfileFromConfig(baseCfg);
      renderTableWizardButtons(baseCfg.by);
      refreshTableFilterOptions(baseCfg);
      renderForConfig(baseCfg);
      setStatus("Showing active setup table key");
      return;
    }

    var parsed = parseStoreKey(selectedKey);
    if (!parsed) {
      window.alert("Selected table filter key is invalid.");
      var failNorm = normalizeConfig();
      refreshTableFilterOptions(finalizeConfig(failNorm.cfg));
      return;
    }

    if (els.tableSize) els.tableSize.value = String(parsed.by);
    if (els.min) els.min.value = String(parsed.min);
    if (els.max) els.max.value = String(parsed.max);

    var norm = normalizeConfig();
    var cfg = finalizeConfig(norm.cfg);
    state.tableFilterStoreKey = cfg.storeKey;
    rememberProfileFromConfig(cfg);
    renderTableWizardButtons(cfg.by);
    refreshTableFilterOptions(cfg);
    renderForConfig(cfg);

    if (cfg.storeKey !== parsed.storeKey) {
      window.alert("This table key is not allowed by current policy. Active key was normalized to " + cfg.key + ".");
    }
    setStatus("Table filter applied: " + cfg.key);
  }

  function onResetTableFilter() {
    state.tableFilterStoreKey = "";
    state.historyRangeFromTs = null;
    state.historyRangeToTs = null;
    state.historyPage = 1;
    if (els.tableFilterKey) els.tableFilterKey.value = "";
    if (els.tableFilterFromTs) els.tableFilterFromTs.value = "";
    if (els.tableFilterToTs) els.tableFilterToTs.value = "";
    var norm = normalizeConfig();
    var cfg = finalizeConfig(norm.cfg);
    rememberProfileFromConfig(cfg);
    renderTableWizardButtons(cfg.by);
    refreshTableFilterOptions(cfg);
    renderForConfig(cfg);
    setStatus("Table filter reset to active setup key");
  }

  function onHistoryPageSizeChange() {
    if (!els.historyPageSize) return;
    var size = normalizeHistoryPageSize(els.historyPageSize.value, 100);
    state.historyPageSize = size;
    els.historyPageSize.value = size === 0 ? "all" : String(size);
    state.historyPage = 1;
    var norm = normalizeConfig();
    var cfg = finalizeConfig(norm.cfg);
    rememberProfileFromConfig(cfg);
    renderForConfig(cfg);
  }

  function onHistoryPrevPage() {
    state.historyPage = Math.max(1, (state.historyPage || 1) - 1);
    var norm = normalizeConfig();
    var cfg = finalizeConfig(norm.cfg);
    rememberProfileFromConfig(cfg);
    renderForConfig(cfg);
  }

  function onHistoryNextPage() {
    state.historyPage = (state.historyPage || 1) + 1;
    var norm = normalizeConfig();
    var cfg = finalizeConfig(norm.cfg);
    rememberProfileFromConfig(cfg);
    renderForConfig(cfg);
  }

  function onConfigChange() {
    var norm = normalizeConfig();
    var cfg = finalizeConfig(norm.cfg);
    state.tableFilterStoreKey = cfg.storeKey;
    state.historyPage = 1;
    rememberProfileFromConfig(cfg);
    renderTableWizardButtons(cfg.by);
    renderForConfig(cfg);
  }

  function onTableSizeChange() {
    var by = normalizeTableSizeValue(els.tableSize ? els.tableSize.value : null);
    if (by == null) return;
    if (state.activeWizardBy && by !== state.activeWizardBy) {
      onWizardTableSelect(by);
      return;
    }
    onConfigChange();
  }

  function bind() {
    if (els.savePolicyBtn) els.savePolicyBtn.addEventListener("click", onSavePolicy);
    if (els.allowedSizesInput) els.allowedSizesInput.addEventListener("input", onAllowedSizesInputChange);
    if (els.generateBtn) els.generateBtn.addEventListener("click", onGenerate);
    if (els.predictBtn) els.predictBtn.addEventListener("click", onPredict);
    if (els.clearHistoryBtn) els.clearHistoryBtn.addEventListener("click", onClearHistory);
    if (els.exportJsonBtn) els.exportJsonBtn.addEventListener("click", onExportJson);
    if (els.exportCsvBtn) els.exportCsvBtn.addEventListener("click", onExportCsv);
    if (els.exportAllJsonBtn) els.exportAllJsonBtn.addEventListener("click", onExportAllJson);
    if (els.importBtn) els.importBtn.addEventListener("click", onImportTrigger);
    if (els.importAllJsonBtn) els.importAllJsonBtn.addEventListener("click", onImportAllTrigger);
    if (els.importFile) els.importFile.addEventListener("change", onImportFileChange);
    if (els.importAllFile) els.importAllFile.addEventListener("change", onImportAllFileChange);
    if (els.importGridBtn) els.importGridBtn.addEventListener("click", onImportGrid);
    if (els.clearGridBtn) els.clearGridBtn.addEventListener("click", onClearGrid);
    if (els.buildPasteGridBtn) els.buildPasteGridBtn.addEventListener("click", onBuildPasteGrid);
    if (els.pasteGridRows) els.pasteGridRows.addEventListener("change", onBuildPasteGrid);
    if (els.pasteGridBody) els.pasteGridBody.addEventListener("paste", onPasteGridInput);
    if (els.pasteGridBody) els.pasteGridBody.addEventListener("keydown", onPasteGridKeydown);
    if (els.manualCombo) els.manualCombo.addEventListener("input", onManualInputChange);
    if (els.manualCombo) els.manualCombo.addEventListener("keydown", onManualComboKeydown);
    if (els.addManualBtn) els.addManualBtn.addEventListener("click", onManualAdd);
    if (els.clearManualBtn) els.clearManualBtn.addEventListener("click", onManualClear);
    if (els.wizardSetupBtn) els.wizardSetupBtn.addEventListener("click", function () { setWizardStep("setup"); });
    if (els.wizardSettingsBtn) els.wizardSettingsBtn.addEventListener("click", function () { setWizardStep("settings"); });
    if (els.applyTableFilterBtn) els.applyTableFilterBtn.addEventListener("click", onApplyTableFilter);
    if (els.resetTableFilterBtn) els.resetTableFilterBtn.addEventListener("click", onResetTableFilter);
    if (els.tableFilterKey) els.tableFilterKey.addEventListener("change", onApplyTableFilter);
    if (els.historyPageSize) els.historyPageSize.addEventListener("change", onHistoryPageSizeChange);
    if (els.historyPrevBtn) els.historyPrevBtn.addEventListener("click", onHistoryPrevPage);
    if (els.historyNextBtn) els.historyNextBtn.addEventListener("click", onHistoryNextPage);

    if (els.tableSize) els.tableSize.addEventListener("change", onTableSizeChange);
    if (els.min) els.min.addEventListener("change", onConfigChange);
    if (els.max) els.max.addEventListener("change", onConfigChange);
    if (els.algorithm) els.algorithm.addEventListener("change", onConfigChange);
    if (els.accuracyStyle) els.accuracyStyle.addEventListener("change", onConfigChange);

    var forceSync = function () {
      if (!canUseRemoteStoreApi()) return;
      if (!state.remoteSyncTimer) return;
      window.clearTimeout(state.remoteSyncTimer);
      state.remoteSyncTimer = 0;
      flushRemoteStoreSync();
    };
    window.addEventListener("pagehide", forceSync);
    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "hidden") {
        forceSync();
      }
    });
  }

  function init() {
    state.settings = sanitizeSettingsObject(state.settings || defaultSettingsObject());
    state.algoSnapshots = Object.create(null);
    if (els.historyPageSize) {
      state.historyPageSize = normalizeHistoryPageSize(els.historyPageSize.value, 100);
      els.historyPageSize.value = state.historyPageSize === 0 ? "all" : String(state.historyPageSize);
    } else {
      state.historyPageSize = 100;
    }
    renderPolicyControls();
    setWizardStep(state.wizardStep || "setup");

    state.storeCache = sanitizeStoreObject(loadStoreFromLocalCache()).store;
    var norm = normalizeConfig();
    var cfg = finalizeConfig(norm.cfg);
    cfg = maybeAutoSelectKeyWithHistory(cfg);
    rememberProfileFromConfig(cfg);
    renderTableWizardButtons(cfg.by);
    state.tableFilterStoreKey = cfg.storeKey;
    refreshStatusBoxes(cfg);
    renderGenerated([], cfg);
    renderForConfig(cfg);
    setStatus("Ready");

    bootstrapStoreFromApi(function (ok) {
      state.remoteBootstrapped = true;

      var latestNorm = normalizeConfig();
      var latestCfg = finalizeConfig(latestNorm.cfg);
      latestCfg = maybeAutoSelectKeyWithHistory(latestCfg);
      rememberProfileFromConfig(latestCfg);
      renderTableWizardButtons(latestCfg.by);
      renderForConfig(latestCfg);

      if (!els.statusText) return;
      var current = String(els.statusText.textContent || "");
      if (current.indexOf("Ready") !== 0) return;

      if (ok) {
        setStatus("Ready (database)");
      } else if (canUseRemoteStoreApi()) {
        setStatus("Ready (local cache)");
      }
    });
  }

  bind();
  init();
})();


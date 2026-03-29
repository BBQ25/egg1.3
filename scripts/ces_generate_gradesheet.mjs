import fs from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { chromium } from "playwright";

function log(message) {
  process.stderr.write(`[ces-playwright] ${message}\n`);
}

function normalize(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/\s+/g, " ")
    .trim();
}

function parseFilenameFromContentDisposition(value) {
  const raw = String(value || "");
  const utf8 = raw.match(/filename\*=UTF-8''([^;]+)/i);
  if (utf8 && utf8[1]) {
    try {
      return decodeURIComponent(utf8[1].replace(/["']/g, "").trim());
    } catch {
      return utf8[1].replace(/["']/g, "").trim();
    }
  }

  const basic = raw.match(/filename=([^;]+)/i);
  if (basic && basic[1]) {
    return basic[1].replace(/["']/g, "").trim();
  }

  return "";
}

function schoolYearCandidates(schoolYear) {
  const raw = String(schoolYear || "").trim();
  const candidates = new Set([raw]);
  const m = raw.match(/^(\d{4})\s*-\s*(\d{4})$/);
  if (m) {
    candidates.add(m[1]);
    candidates.add(m[2]);
    candidates.add(`${m[1]}-${m[2]}`);
    candidates.add(`${m[1]} - ${m[2]}`);
  }
  return Array.from(candidates).filter(Boolean);
}

function semesterCandidates(semester) {
  const raw = normalize(semester);
  const candidates = new Set([semester, raw]);

  if (raw.includes("1") || raw.includes("first")) {
    ["1st semester", "first semester", "1st", "first", "1"].forEach((v) =>
      candidates.add(v)
    );
  } else if (raw.includes("2") || raw.includes("second")) {
    ["2nd semester", "second semester", "2nd", "second", "2"].forEach((v) =>
      candidates.add(v)
    );
  } else if (raw.includes("sum")) {
    ["summer", "sum", "sum2"].forEach((v) => candidates.add(v));
  }

  return Array.from(candidates).filter(Boolean);
}

function campusCandidates(campus) {
  const raw = String(campus || "").trim();
  const normalized = normalize(raw);
  const candidates = new Set([raw, normalized, `${raw} Campus`, `${normalized} campus`]);

  if (normalized === "bontoc") {
    candidates.add("bontoc campus");
  }

  return Array.from(candidates).filter(Boolean);
}

async function readLoginMessage(page) {
  return page.evaluate(() => {
    const container = document.querySelector("#loginmsg");
    if (!container) {
      return "";
    }

    return String(container.textContent || "")
      .replace(/\s+/g, " ")
      .trim();
  });
}

function pickLoginMessage(payload) {
  if (!payload || typeof payload !== "object") {
    return "";
  }

  const direct = [
    payload.Message,
    payload.message,
    payload.error,
    payload.errors,
    payload.detail,
  ];

  for (const value of direct) {
    if (typeof value === "string" && value.trim() !== "") {
      return value.trim();
    }
  }

  if (payload.errors && typeof payload.errors === "object") {
    const flattened = Object.values(payload.errors)
      .flat()
      .map((v) => String(v || "").trim())
      .filter(Boolean);

    if (flattened.length > 0) {
      return flattened.join("; ");
    }
  }

  return "";
}

async function fileExists(filePath) {
  try {
    await fs.access(filePath);
    return true;
  } catch {
    return false;
  }
}

async function readPayload(payloadPath) {
  const content = await fs.readFile(payloadPath, "utf8");
  return JSON.parse(content);
}

async function ensureParentDir(filePath) {
  const parent = path.dirname(filePath);
  await fs.mkdir(parent, { recursive: true });
}

async function waitForAnySelector(page, selectors, timeoutMs = 15000) {
  const started = Date.now();

  while (Date.now() - started <= timeoutMs) {
    for (const selector of selectors) {
      const found = await page.$(selector);
      if (found) {
        return selector;
      }
    }
    await page.waitForTimeout(200);
  }

  return null;
}

async function selectOptionByText(page, selectors, textCandidates, label) {
  const selector = await waitForAnySelector(page, selectors);
  if (!selector) {
    throw new Error(`${label} select was not found.`);
  }

  const normalizedCandidates = textCandidates.map((v) => normalize(v));
  const success = await page.evaluate(
    ({ selector: sel, normalized }) => {
      const select = document.querySelector(sel);
      if (!select) return false;

      const options = Array.from(select.options || []);
      for (const expected of normalized) {
        const match = options.find((option) => {
          const text = String(option.textContent || option.label || option.value)
            .toLowerCase()
            .replace(/\s+/g, " ")
            .trim();

          return text === expected || text.includes(expected) || expected.includes(text);
        });

        if (!match) {
          continue;
        }

        select.value = match.value;
        select.dispatchEvent(new Event("input", { bubbles: true }));
        select.dispatchEvent(new Event("change", { bubbles: true }));
        return true;
      }

      return false;
    },
    { selector, normalized: normalizedCandidates }
  );

  if (!success) {
    throw new Error(`${label} option was not found.`);
  }
}

async function clickByText(page, selectors, textCandidates, timeoutMs = 15000) {
  const started = Date.now();
  const normalizedCandidates = textCandidates.map((v) => normalize(v));

  while (Date.now() - started <= timeoutMs) {
    const clicked = await page.evaluate(
      ({ selectors: sels, normalized }) => {
        const nodes = [];
        sels.forEach((sel) => {
          document.querySelectorAll(sel).forEach((node) => nodes.push(node));
        });

        for (const node of nodes) {
          const label = String(node.textContent || node.value || "")
            .toLowerCase()
            .replace(/\s+/g, " ")
            .trim();

          if (
            normalized.some(
              (expected) =>
                label === expected ||
                label.includes(expected) ||
                expected.includes(label)
            )
          ) {
            node.click();
            return true;
          }
        }

        return false;
      },
      { selectors, normalized: normalizedCandidates }
    );

    if (clicked) {
      return true;
    }

    await page.waitForTimeout(250);
  }

  return false;
}

async function ensureLoggedIn(page, payload) {
  const baseUrl = payload.baseUrl.replace(/\/+$/, "");
  const encodeUrl = `${baseUrl}${payload.encodePath || "/teacher/encode-grades"}`;
  const loginUrl = `${baseUrl}${payload.loginPath || "/auth/login-basic"}`;

  await page.goto(encodeUrl, { waitUntil: "domcontentloaded", timeout: 45000 });

  const onLoginPage = normalize(page.url()).includes("/auth/login");
  if (!onLoginPage) {
    return;
  }

  log("Session is not authenticated. Performing login...");
  await page.goto(loginUrl, { waitUntil: "domcontentloaded", timeout: 45000 });

  const desiredCampus = String(payload.campus || "Bontoc").trim();

  const campusSelected = await page.evaluate(
    ({ candidates }) => {
      const normalized = candidates
        .map((v) => String(v || "").toLowerCase().replace(/\s+/g, " ").trim())
        .filter(Boolean);

      const allSelects = Array.from(document.querySelectorAll("select"));

      const explicit = allSelects.find((select) => {
        const key = String(select.id || select.name || "")
          .toLowerCase()
          .trim();
        return key === "campus" || key === "campus_id" || key.includes("campus");
      });

      const fallback = allSelects.find((select) => {
        const texts = Array.from(select.options || []).map((opt) =>
          String(opt.textContent || opt.label || opt.value || "")
            .toLowerCase()
            .replace(/\s+/g, " ")
            .trim()
        );
        return texts.some((text) => text.includes("campus") || text === "select campus");
      });

      const targetSelect = explicit || fallback;
      if (!targetSelect) {
        return false;
      }

      const options = Array.from(targetSelect.options || []);
      for (const expected of normalized) {
        const match = options.find((opt) => {
          const text = String(opt.textContent || opt.label || opt.value || "")
            .toLowerCase()
            .replace(/\s+/g, " ")
            .trim();
          return text === expected || text.includes(expected) || expected.includes(text);
        });

        if (!match) {
          continue;
        }

        targetSelect.value = match.value;
        targetSelect.dispatchEvent(new Event("input", { bubbles: true }));
        targetSelect.dispatchEvent(new Event("change", { bubbles: true }));
        return true;
      }

      return false;
    },
    { candidates: campusCandidates(desiredCampus) }
  );

  if (!campusSelected) {
    throw new Error(`Could not select campus "${desiredCampus}" on CES login page.`);
  }

  const userSelector = await waitForAnySelector(page, [
    "input[name='username']",
    "input#username",
    "input[name='email']",
    "input#email",
    "input[type='text']",
  ]);
  const passwordSelector = await waitForAnySelector(page, [
    "input[name='password']",
    "input#password",
    "input[type='password']",
  ]);

  if (!userSelector || !passwordSelector) {
    throw new Error("Could not find CES login form inputs.");
  }

  await page.fill(userSelector, payload.username);
  await page.fill(passwordSelector, payload.password);

  const loginAttemptPromise = page
    .waitForResponse(
      (response) =>
        response.request().method().toUpperCase() === "POST" &&
        response.url().includes("/auth/login-attempt"),
      { timeout: 30000 }
    )
    .catch(() => null);

  const submitClicked = await clickByText(
    page,
    ["button", "input[type='submit']", "a"],
    ["login", "sign in", "submit"],
    5000
  );

  if (!submitClicked) {
    const submitSelector = await waitForAnySelector(page, [
      "button[type='submit']",
      "input[type='submit']",
    ]);

    if (!submitSelector) {
      throw new Error("Could not find CES login submit button.");
    }

    await page.click(submitSelector);
  }

  const loginAttemptResponse = await loginAttemptPromise;
  if (loginAttemptResponse) {
    let loginData = null;
    let loginText = "";
    try {
      loginData = await loginAttemptResponse.json();
    } catch {
      try {
        loginText = await loginAttemptResponse.text();
      } catch {
        loginText = "";
      }
    }

    const statusCode = Number(
      loginData?.status_code ??
        loginData?.status ??
        loginData?.code ??
        loginData?.statusCode ??
        NaN
    );
    const loginSucceeded =
      (Number.isFinite(statusCode) && statusCode === 0) ||
      loginData?.ok === true ||
      loginData?.success === true;

    if (!loginSucceeded) {
      const payloadMessage = pickLoginMessage(loginData);
      const pageMessage = await readLoginMessage(page);
      const rawMessage = [payloadMessage, pageMessage, loginText]
        .map((v) => String(v || "").trim())
        .find((v) => v !== "");

      const detail =
        rawMessage !== ""
          ? rawMessage
          : `login-attempt returned status ${loginAttemptResponse.status()}`;

      throw new Error(`CES login failed: ${detail} (selected campus: ${desiredCampus}).`);
    }
  } else {
    const pageMessage = await readLoginMessage(page);
    if (pageMessage !== "" && !normalize(pageMessage).includes("logging in")) {
      throw new Error(`CES login failed: ${pageMessage} (selected campus: ${desiredCampus}).`);
    }
  }

  await page.waitForTimeout(600);
  await page.goto(encodeUrl, { waitUntil: "domcontentloaded", timeout: 45000 });

  if (normalize(page.url()).includes("/auth/login")) {
    const pageMessage = await readLoginMessage(page);
    const detail = pageMessage !== "" ? ` ${pageMessage}` : "";
    throw new Error(
      `CES login failed. Check CES credentials and campus selection (selected campus: ${desiredCampus}).${detail}`
    );
  }
}

async function run() {
  const payloadPath = process.argv[2];
  if (!payloadPath) {
    throw new Error("Payload path is required.");
  }

  const payload = await readPayload(payloadPath);
  const mode = String(payload.mode || "generate_pdf").toLowerCase();
  const requiredBase = ["baseUrl", "username", "password"];
  const requiredForGenerate = ["schoolYear", "semester", "sectionCode", "outputPath"];
  const required = mode === "test_connection" ? requiredBase : requiredBase.concat(requiredForGenerate);

  for (const key of required) {
    if (!String(payload[key] || "").trim()) {
      throw new Error(`Missing required payload key: ${key}`);
    }
  }

  if (mode !== "test_connection") {
    await ensureParentDir(payload.outputPath);
  }

  if (payload.sessionStatePath) {
    await ensureParentDir(payload.sessionStatePath);
  }

  const launchOptions = {
    headless: payload.headless !== false,
  };
  const slowMoMs = Math.max(0, Number(payload.slowMoMs || 0));
  if (slowMoMs > 0) {
    launchOptions.slowMo = slowMoMs;
  }

  let browser;
  try {
    browser = await chromium.launch(launchOptions);

    const contextOptions = {
      acceptDownloads: true,
      ignoreHTTPSErrors: true,
    };

    if (payload.sessionStatePath && (await fileExists(payload.sessionStatePath))) {
      contextOptions.storageState = payload.sessionStatePath;
      log("Using existing CES session state.");
    }

    const context = await browser.newContext(contextOptions);
    const page = await context.newPage();

    await ensureLoggedIn(page, payload);

    if (payload.sessionStatePath) {
      await context.storageState({ path: payload.sessionStatePath });
    }

    if (mode === "test_connection") {
      process.stdout.write(
        JSON.stringify({
          ok: true,
          mode,
          currentUrl: page.url(),
          authenticated: !normalize(page.url()).includes("/auth/login"),
          timestamp: new Date().toISOString(),
        })
      );
      return;
    }

    log("Selecting School Year and Semester...");
    await selectOptionByText(
      page,
      ["select#SchoolYear", "select[name='SchoolYear']", "select#school_year", "select[name='school_year']"],
      schoolYearCandidates(payload.schoolYear),
      "School Year"
    );

    await page.waitForTimeout(300);

    await selectOptionByText(
      page,
      ["select#Semester", "select[name='Semester']", "select#semester", "select[name='semester']"],
      semesterCandidates(payload.semester),
      "Semester"
    );

    await page.waitForTimeout(300);

    const viewClicked = await clickByText(
      page,
      ["a", "button", "input[type='button']", "input[type='submit']"],
      ["view"],
      4000
    );

    if (viewClicked) {
      log("Clicked View button.");
      await Promise.race([
        page.waitForResponse(
          (response) =>
            response.url().includes("/teacher/view-encode-grades") &&
            response.request().method().toUpperCase() === "POST",
          { timeout: 12000 }
        ).catch(() => null),
        page.waitForTimeout(1800),
      ]);
    } else {
      log("View button not found; continuing.");
    }

    log(`Selecting section ${payload.sectionCode}...`);
    const sectionClicked = await clickByText(
      page,
      ["a", "button", "td", "tr"],
      [payload.sectionCode],
      15000
    );

    if (!sectionClicked) {
      throw new Error(`Section "${payload.sectionCode}" was not found.`);
    }

    await page.waitForTimeout(1200);

    const downloadPromise = page
      .waitForEvent("download", { timeout: 45000 })
      .then((download) => ({ kind: "download", download }))
      .catch(() => null);

    const pdfResponsePromise = page
      .waitForResponse(
        (response) =>
          response.url().includes("/report/gradesheet") &&
          response.request().method().toUpperCase() === "POST" &&
          response.status() >= 200 &&
          response.status() < 400,
        { timeout: 45000 }
      )
      .then((response) => ({ kind: "response", response }))
      .catch(() => null);

    log("Clicking Generate Grade Sheet...");
    const generateClicked = await clickByText(
      page,
      ["a", "button", "input[type='button']", "input[type='submit']"],
      ["generate grade sheet", "geneerate grade sheet"],
      12000
    );

    if (!generateClicked) {
      throw new Error("Generate Grade Sheet button was not found.");
    }

    const firstResult = await Promise.race([
      downloadPromise,
      pdfResponsePromise,
      page.waitForTimeout(46000).then(() => null),
    ]);

    let finalResult = firstResult;
    if (!finalResult) {
      const [downloadResult, responseResult] = await Promise.all([downloadPromise, pdfResponsePromise]);
      finalResult = downloadResult || responseResult;
    }

    if (!finalResult) {
      throw new Error("Failed to capture CES Grade Sheet PDF.");
    }

    let filename = "";
    if (finalResult.kind === "download") {
      const suggested = finalResult.download.suggestedFilename();
      filename = suggested || "";
      await finalResult.download.saveAs(payload.outputPath);
    } else {
      const headers = finalResult.response.headers();
      filename = parseFilenameFromContentDisposition(headers["content-disposition"]);
      const buffer = await finalResult.response.body();
      await fs.writeFile(payload.outputPath, buffer);
    }

    if (!(await fileExists(payload.outputPath))) {
      throw new Error("CES PDF output file was not written.");
    }

    if (!filename) {
      filename = `${payload.sectionCode}-${payload.schoolYear}.pdf`;
    }

    process.stdout.write(
      JSON.stringify({
        ok: true,
        outputPath: payload.outputPath,
        filename,
      })
    );
  } finally {
    if (browser) {
      await browser.close();
    }
  }
}

run().catch((error) => {
  const message = error instanceof Error ? error.message : String(error);
  process.stderr.write(`[ces-playwright] ERROR: ${message}\n`);
  process.exit(1);
});

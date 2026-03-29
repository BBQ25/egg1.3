import fs from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { chromium } from "playwright";

function log(message) {
  process.stderr.write(`[hrmis-playwright] ${message}\n`);
}

function normalize(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/\s+/g, " ")
    .trim();
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

function extractMessageFromPayload(payload) {
  if (!payload || typeof payload !== "object") {
    return "";
  }

  const direct = [
    payload.message,
    payload.Message,
    payload.msg,
    payload.error,
    payload.detail,
  ];

  for (const candidate of direct) {
    if (typeof candidate === "string" && candidate.trim() !== "") {
      return candidate.trim();
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

async function readVisibleMessage(page) {
  return page.evaluate(() => {
    const selectors = [
      ".swal2-popup .swal2-html-container",
      ".swal2-popup .swal2-title",
      "#toast-container .toast .toast-message",
      "#toast-container .toast-message",
      ".toast-message",
      ".alert.alert-success",
      ".alert.alert-danger",
      ".alert",
      ".text-danger",
      ".text-success",
    ];

    for (const selector of selectors) {
      const nodes = Array.from(document.querySelectorAll(selector));
      for (const node of nodes) {
        const style = window.getComputedStyle(node);
        if (!style || style.display === "none" || style.visibility === "hidden") {
          continue;
        }

        const text = String(node.textContent || "")
          .replace(/\s+/g, " ")
          .trim();

        if (text !== "") {
          return text;
        }
      }
    }

    return "";
  });
}

function resolveSigninUrl(baseUrl, signinPath) {
  const base = String(baseUrl || "").replace(/\/+$/, "");
  const pathPart = "/" + String(signinPath || "/signin").replace(/^\/+/, "");
  return `${base}${pathPart}`;
}

function resolveGoogleDtrAuthUrl(baseUrl, googleDtrAuthPath) {
  const base = String(baseUrl || "").replace(/\/+$/, "");
  const pathPart =
    "/" + String(googleDtrAuthPath || "/auth/googledtr").replace(/^\/+/, "");
  return `${base}${pathPart}`;
}

async function tryFillGoogleEmail(page, email) {
  const normalizedEmail = String(email || "").trim();
  if (normalizedEmail === "") {
    return false;
  }

  try {
    const emailInput = page.locator("input#identifierId, input[type='email']").first();
    await emailInput.waitFor({ state: "visible", timeout: 1200 });
    await emailInput.fill(normalizedEmail);

    const nextButton = page
      .locator("#identifierNext button, #identifierNext, button:has-text('Next')")
      .first();

    try {
      await nextButton.click({ timeout: 2500 });
    } catch {
      await emailInput.press("Enter");
    }

    log(`Prefilled Google email: ${normalizedEmail}`);
    return true;
  } catch {
    return false;
  }
}

async function loginViaGoogleDtr(page, payload) {
  const googleDtrAuthUrl = resolveGoogleDtrAuthUrl(payload.baseUrl, payload.googleDtrAuthPath);
  await page.goto(googleDtrAuthUrl, { waitUntil: "domcontentloaded", timeout: 45000 });

  const maxWaitMs = 180000;
  const startedAt = Date.now();
  let prefilledOnce = false;

  while (Date.now() - startedAt <= maxWaitMs) {
    const currentUrl = page.url();
    const normalizedUrl = normalize(currentUrl);

    if (currentUrl.startsWith(payload.baseUrl) && !normalizedUrl.includes("/signin")) {
      return;
    }

    if (currentUrl.startsWith("https://accounts.google.com")) {
      if (!prefilledOnce) {
        prefilledOnce = await tryFillGoogleEmail(page, payload.email);
      } else {
        // Keep trying if Google switched back to email step.
        await tryFillGoogleEmail(page, payload.email);
      }
    } else if (normalizedUrl.includes("/signin")) {
      // Retry entering the My DTR Google flow when still on HRMIS signin page.
      const relinkClicked = await page.evaluate(() => {
        const link = document.querySelector("a.signInGDtr, a[href*='/auth/googledtr']");
        if (!link) {
          return false;
        }
        link.click();
        return true;
      });

      if (!relinkClicked) {
        await page.goto(googleDtrAuthUrl, { waitUntil: "domcontentloaded", timeout: 45000 });
      }
    }

    await page.waitForTimeout(1000);
  }

  const emailLabel = String(payload.email || "").trim();
  const emailHint = emailLabel !== "" ? ` for ${emailLabel}` : "";
  throw new Error(
    `Google sign-in did not complete within 3 minutes${emailHint}. Complete Google confirmation in headed mode, then retry.`
  );
}

async function loginViaEndpoint(context, page, payload) {
  const csrfToken = await page.evaluate(() => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
      const value = String(meta.getAttribute("content") || "").trim();
      if (value !== "") {
        return value;
      }
    }

    const tokenInput = document.querySelector('input[name="_token"]');
    if (tokenInput) {
      const value = String(tokenInput.value || "").trim();
      if (value !== "") {
        return value;
      }
    }

    return "";
  });

  if (csrfToken === "") {
    throw new Error("Could not read HRMIS CSRF token from signin page.");
  }

  const signinUrl = resolveSigninUrl(payload.baseUrl, payload.signinPath);
  const username = String(payload.username || payload.email || "").trim();
  const response = await context.request.post(signinUrl, {
    form: {
      username,
      password: String(payload.password || ""),
      _token: csrfToken,
    },
    headers: {
      "X-CSRF-TOKEN": csrfToken,
      "X-Requested-With": "XMLHttpRequest",
      Accept: "application/json, text/plain, */*",
    },
  });

  const responseText = await response.text();
  let json = null;
  try {
    json = JSON.parse(responseText);
  } catch {
    json = null;
  }

  const statusCode = Number(
    json?.status ?? json?.status_code ?? json?.code ?? json?.statusCode ?? Number.NaN
  );
  const loginSucceeded =
    (Number.isFinite(statusCode) && statusCode === 200) ||
    json?.ok === true ||
    json?.success === true;

  if (!response.ok() || !loginSucceeded) {
    const message = extractMessageFromPayload(json) || responseText || "Unknown HRMIS signin response.";
    throw new Error(`HRMIS login failed: ${message}`);
  }
}

async function ensureAuthenticated(page, context, payload) {
  await page.goto(payload.dtrUrl, { waitUntil: "domcontentloaded", timeout: 45000 });

  if (!normalize(page.url()).includes("/signin")) {
    return "session";
  }

  log("Session is not authenticated. Performing login...");

  const username = String(payload.username || payload.email || "").trim();
  const password = String(payload.password || "").trim();
  const canUsePasswordLogin = username !== "" && password !== "";

  if (canUsePasswordLogin) {
    await loginViaEndpoint(context, page, payload);
  } else {
    if (payload.headless !== false) {
      const emailLabel = String(payload.email || "").trim();
      const emailHint = emailLabel !== "" ? ` for ${emailLabel}` : "";
      throw new Error(
        `No active HRMIS session${emailHint}. HRMIS uses Google sign-in; enable Visual Debug Mode once to complete Google login.`
      );
    }

    await loginViaGoogleDtr(page, payload);
  }

  await page.waitForTimeout(400);
  await page.goto(payload.dtrUrl, { waitUntil: "domcontentloaded", timeout: 45000 });

  if (normalize(page.url()).includes("/signin")) {
    throw new Error("HRMIS login failed. Google sign-in did not establish a valid HRMIS session.");
  }

  return canUsePasswordLogin ? "password" : "google";
}

async function clickTimeInAndCollect(page, payload) {
  const dialogMessages = [];
  const responseEvents = [];

  const dialogHandler = async (dialog) => {
    dialogMessages.push(String(dialog.message() || "").trim());
    await dialog.accept();
  };

  const responseHandler = async (response) => {
    const request = response.request();
    if (request.method().toUpperCase() !== "POST") {
      return;
    }

    if (!response.url().startsWith(payload.baseUrl)) {
      return;
    }

    const headers = response.headers();
    const contentType = normalize(headers["content-type"] || "");
    if (!contentType.includes("json") && !contentType.includes("text")) {
      return;
    }

    let json = null;
    let text = "";
    try {
      json = await response.json();
    } catch {
      try {
        text = await response.text();
      } catch {
        text = "";
      }
    }

    const statusCode = Number(
      json?.status ?? json?.status_code ?? json?.code ?? json?.statusCode ?? Number.NaN
    );

    responseEvents.push({
      url: response.url(),
      httpStatus: response.status(),
      statusCode: Number.isFinite(statusCode) ? statusCode : null,
      message: extractMessageFromPayload(json) || String(text || "").trim(),
      ok:
        (Number.isFinite(statusCode) && statusCode === 200) ||
        json?.ok === true ||
        json?.success === true,
    });
  };

  page.on("dialog", dialogHandler);
  page.on("response", responseHandler);

  const selectors = [
    "#btnTimeIn",
    "button#btnTimeIn",
    "a#btnTimeIn",
    "input#btnTimeIn",
    "[data-test='time-inc-btn']",
    "button[data-test='time-inc-btn']",
    "button:has-text('Time In')",
    "a:has-text('Time In')",
  ];

  let clickedSelector = null;
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    const count = await locator.count();
    if (count === 0) {
      continue;
    }

    try {
      await locator.click({ timeout: 5000 });
      clickedSelector = selector;
      break;
    } catch {
      continue;
    }
  }

  if (!clickedSelector) {
    const fallbackClicked = await page.evaluate(() => {
      const nodes = Array.from(
        document.querySelectorAll("button, a, input[type='button'], input[type='submit']")
      );

      const target = nodes.find((node) => {
        const id = String(node.id || "").toLowerCase().trim();
        const test = String(node.getAttribute("data-test") || "").toLowerCase().trim();
        const text = String(node.textContent || node.value || "")
          .toLowerCase()
          .replace(/\s+/g, " ")
          .trim();

        return (
          id === "btntimein" ||
          test === "time-inc-btn" ||
          text === "time in" ||
          text.includes("time in")
        );
      });

      if (!target) {
        return "";
      }

      target.click();
      return target.id || target.getAttribute("data-test") || "text-match";
    });

    if (fallbackClicked) {
      clickedSelector = fallbackClicked;
    }
  }

  if (!clickedSelector) {
    page.off("dialog", dialogHandler);
    page.off("response", responseHandler);
    throw new Error('Time In button was not found. Expected selector "#btnTimeIn".');
  }

  await Promise.race([
    page.waitForResponse(
      (response) =>
        response.request().method().toUpperCase() === "POST" &&
        response.url().startsWith(payload.baseUrl),
      { timeout: 12000 }
    ),
    page.waitForTimeout(2500),
  ]).catch(() => null);

  await page.waitForTimeout(1500);

  page.off("dialog", dialogHandler);
  page.off("response", responseHandler);

  const visibleMessage = await readVisibleMessage(page);
  const responseMessage = responseEvents
    .map((item) => String(item.message || "").trim())
    .find((item) => item !== "");
  const dialogMessage = dialogMessages.find((item) => item !== "");

  const finalMessage =
    responseMessage || dialogMessage || visibleMessage || "Time In clicked. No explicit alert message captured.";

  let ok = true;
  const responseStatus = responseEvents.find((item) => item.statusCode !== null);
  if (responseStatus && responseStatus.ok === false) {
    ok = false;
  }

  const normalizedMessage = normalize(finalMessage);
  if (
    normalizedMessage.includes("error") ||
    normalizedMessage.includes("failed") ||
    normalizedMessage.includes("invalid") ||
    normalizedMessage.includes("unable")
  ) {
    ok = false;
  }

  return {
    ok,
    message: finalMessage,
    clickedSelector,
    dialogMessages,
    responseEvents,
    visibleMessage,
  };
}

async function run() {
  const payloadPath = process.argv[2];
  if (!payloadPath) {
    throw new Error("Payload path is required.");
  }

  const payload = await readPayload(payloadPath);
  const baseUrl = String(payload.baseUrl || "").replace(/\/+$/, "");
  const dtrUrl = String(payload.dtrUrl || "").trim();

  if (baseUrl === "") {
    throw new Error("Missing required payload key: baseUrl");
  }

  if (dtrUrl === "") {
    throw new Error("Missing required payload key: dtrUrl");
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
      ignoreHTTPSErrors: true,
    };

    if (payload.sessionStatePath && (await fileExists(payload.sessionStatePath))) {
      contextOptions.storageState = payload.sessionStatePath;
      log("Using existing HRMIS session state.");
    }

    const context = await browser.newContext(contextOptions);
    const page = await context.newPage();

    const authMode = await ensureAuthenticated(page, context, payload);
    await page.goto(dtrUrl, { waitUntil: "domcontentloaded", timeout: 45000 });

    if (normalize(page.url()).includes("/signin")) {
      throw new Error("HRMIS session is still not authenticated after login attempt.");
    }

    const result = await clickTimeInAndCollect(page, payload);

    if (payload.sessionStatePath) {
      await context.storageState({ path: payload.sessionStatePath });
    }

    process.stdout.write(
      JSON.stringify({
        ok: Boolean(result.ok),
        message: String(result.message || "").trim(),
        clickedSelector: result.clickedSelector,
        currentUrl: page.url(),
        dialogMessages: result.dialogMessages,
        visibleMessage: result.visibleMessage,
        responseEvents: result.responseEvents,
        authMode,
        timestamp: new Date().toISOString(),
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
  process.stderr.write(`[hrmis-playwright] ERROR: ${message}\n`);
  process.exit(1);
});

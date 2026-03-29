import { execFile, spawn } from "node:child_process";
import fs from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { promisify } from "node:util";
import { chromium } from "playwright";

const execFileAsync = promisify(execFile);

const DEFAULT_AVD_NAME = process.env.PWA_TEST_AVD || "Medium_Phone_API_36.1";
const DEFAULT_LOCAL_APP_URL =
  process.env.PWA_TEST_LOCAL_URL || "http://localhost/sumacot/egg1.3";
const DEFAULT_CHROME_PACKAGE = "com.android.chrome";
const DEFAULT_LOCALHOST_DEVICE_PORT = Number(process.env.PWA_TEST_DEVICE_PORT || 8765);

function log(message) {
  process.stderr.write(`[pwa-emulator-test] ${message}\n`);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function normalize(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/\s+/g, " ")
    .trim();
}

function decodeXml(value) {
  return String(value || "")
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&amp;/g, "&");
}

function normalizeUrl(rawUrl) {
  const input = String(rawUrl || "").trim();
  const source = input === "" ? DEFAULT_LOCAL_APP_URL : input;

  let url;
  try {
    url = new URL(source);
  } catch {
    throw new Error(`Invalid base URL: ${source}`);
  }

  return url;
}

function defaultPortForProtocol(protocol) {
  return protocol === "https:" ? 443 : 80;
}

function resolveBaseTarget(rawUrl) {
  const source = normalizeUrl(rawUrl);
  const sourcePort = Number(source.port || defaultPortForProtocol(source.protocol));
  const emulatorUrl = new URL(source.toString());
  let reverseProxy = null;

  if (source.hostname === "localhost" || source.hostname === "127.0.0.1") {
    const defaultDevicePort = sourcePort >= 1024 ? sourcePort : DEFAULT_LOCALHOST_DEVICE_PORT;
    const devicePort = Number.isFinite(defaultDevicePort) && defaultDevicePort > 0
      ? defaultDevicePort
      : 8765;
    emulatorUrl.hostname = "localhost";
    emulatorUrl.port = String(devicePort);

    reverseProxy = {
      hostPort: sourcePort,
      devicePort,
    };
  } else if (source.hostname === "0.0.0.0") {
    emulatorUrl.hostname = "10.0.2.2";
  }

  return {
    sourceUrl: source.toString().replace(/\/+$/, ""),
    emulatorUrl: emulatorUrl.toString().replace(/\/+$/, ""),
    reverseProxy,
  };
}

function parseArgs() {
  const args = process.argv.slice(2);
  const defaultTarget = resolveBaseTarget(DEFAULT_LOCAL_APP_URL);
  const result = {
    avd: DEFAULT_AVD_NAME,
    sourceUrl: defaultTarget.sourceUrl,
    baseUrl: defaultTarget.emulatorUrl,
    reverseProxy: defaultTarget.reverseProxy,
    skipInstall: false,
    cleanInstall: true,
  };

  for (let i = 0; i < args.length; i += 1) {
    const arg = args[i];
    if ((arg === "--avd" || arg === "-a") && args[i + 1]) {
      result.avd = args[i + 1];
      i += 1;
      continue;
    }

    if ((arg === "--base-url" || arg === "-u") && args[i + 1]) {
      const target = resolveBaseTarget(args[i + 1]);
      result.sourceUrl = target.sourceUrl;
      result.baseUrl = target.emulatorUrl;
      result.reverseProxy = target.reverseProxy;
      i += 1;
      continue;
    }

    if (arg === "--skip-install") {
      result.skipInstall = true;
      continue;
    }

    if (arg === "--no-clean-install") {
      result.cleanInstall = false;
      continue;
    }

    if (arg === "--help" || arg === "-h") {
      process.stdout.write(
        [
          "Usage: node scripts/pwa_emulator_test.mjs [options]",
          "",
          "Options:",
          "  --avd, -a <name>       AVD name to boot if no emulator is running.",
          "  --base-url, -u <url>   Base app URL (localhost uses adb reverse by default).",
          "  --skip-install         Skip install-flow actions and run update checks only.",
          "  --no-clean-install     Keep current Chrome/WebAPK state before install flow.",
        ].join("\n"),
      );
      process.exit(0);
    }
  }

  return result;
}

function parseCacheVersion(swSource) {
  const match = String(swSource || "").match(/const CACHE_VERSION = '([^']+)';/);
  return match ? match[1] : null;
}

async function fileExists(filePath) {
  try {
    await fs.access(filePath);
    return true;
  } catch {
    return false;
  }
}

async function resolveAndroidTools() {
  const localSdk = process.env.ANDROID_SDK_ROOT
    || process.env.ANDROID_HOME
    || path.join(process.env.LOCALAPPDATA || "", "Android", "Sdk");

  const adbPath = process.env.ADB_PATH || path.join(localSdk, "platform-tools", "adb.exe");
  const emulatorPath = process.env.EMULATOR_PATH || path.join(localSdk, "emulator", "emulator.exe");

  if (!(await fileExists(adbPath))) {
    throw new Error(`adb was not found. Expected path: ${adbPath}`);
  }

  if (!(await fileExists(emulatorPath))) {
    throw new Error(`emulator was not found. Expected path: ${emulatorPath}`);
  }

  return { adbPath, emulatorPath };
}

async function runCommand(command, args, options = {}) {
  const {
    timeoutMs = 45000,
    allowFailure = false,
    maxBuffer = 16 * 1024 * 1024,
  } = options;

  try {
    const result = await execFileAsync(command, args, {
      encoding: "utf8",
      timeout: timeoutMs,
      maxBuffer,
      windowsHide: true,
    });
    return {
      ok: true,
      stdout: String(result.stdout || ""),
      stderr: String(result.stderr || ""),
      exitCode: 0,
    };
  } catch (error) {
    const stdout = String(error.stdout || "");
    const stderr = String(error.stderr || "");
    const exitCode = Number.isInteger(error.code) ? error.code : 1;
    if (!allowFailure) {
      const detail = [stdout.trim(), stderr.trim()].filter(Boolean).join("\n");
      throw new Error(
        `Command failed: ${command} ${args.join(" ")}${detail ? `\n${detail}` : ""}`,
      );
    }

    return {
      ok: false,
      stdout,
      stderr,
      exitCode,
    };
  }
}

async function adb(adbPath, args, options = {}) {
  return runCommand(adbPath, args, options);
}

function parseAdbDevices(output) {
  return String(output || "")
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== "" && !line.startsWith("List of devices attached"))
    .map((line) => {
      const parts = line.split(/\s+/);
      return {
        serial: parts[0],
        state: parts[1] || "",
      };
    });
}

async function listAvds(emulatorPath) {
  const result = await runCommand(emulatorPath, ["-list-avds"], {
    timeoutMs: 20000,
  });
  return result.stdout
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
}

async function ensureAdbDeviceReady(adbPath, serial) {
  await adb(adbPath, ["-s", serial, "wait-for-device"], { timeoutMs: 120000 });

  const startedAt = Date.now();
  while (Date.now() - startedAt <= 180000) {
    const boot = await adb(adbPath, ["-s", serial, "shell", "getprop", "sys.boot_completed"], {
      allowFailure: true,
      timeoutMs: 8000,
    });

    if (boot.ok && boot.stdout.trim() === "1") {
      return;
    }

    await sleep(1000);
  }

  throw new Error(`Timed out waiting for Android boot completion on ${serial}.`);
}

function emulatorDevices(devices) {
  return devices.filter((item) => item.serial.startsWith("emulator-"));
}

function firstReadyEmulator(devices) {
  return devices.find((item) => item.state === "device") || null;
}

function summarizeEmulatorStates(devices) {
  if (!devices || devices.length === 0) {
    return "(none)";
  }

  return devices.map((item) => `${item.serial}:${item.state || "unknown"}`).join(", ");
}

async function listEmulatorDevices(adbPath) {
  const devices = parseAdbDevices((await adb(adbPath, ["devices"])).stdout);
  return emulatorDevices(devices);
}

async function restartAdbServer(adbPath) {
  await adb(adbPath, ["kill-server"], { allowFailure: true, timeoutMs: 10000 });
  await sleep(800);
  await adb(adbPath, ["start-server"], { timeoutMs: 15000 });
  await sleep(1500);
}

async function waitForReadyEmulator(adbPath, timeoutMs = 30000) {
  const startedAt = Date.now();
  while (Date.now() - startedAt <= timeoutMs) {
    const devices = await listEmulatorDevices(adbPath);
    const ready = firstReadyEmulator(devices);
    if (ready) {
      return ready;
    }

    await sleep(1000);
  }

  return null;
}

async function killStaleEmulators(adbPath, devices) {
  const staleSerials = [...new Set(devices
    .filter((item) => item.state !== "device")
    .map((item) => item.serial))];

  for (const serial of staleSerials) {
    await adb(adbPath, ["-s", serial, "emu", "kill"], {
      allowFailure: true,
      timeoutMs: 8000,
    });
  }

  await runCommand("taskkill", ["/IM", "emulator.exe", "/F"], {
    allowFailure: true,
    timeoutMs: 10000,
  });
  await runCommand("taskkill", ["/IM", "qemu-system-x86_64.exe", "/F"], {
    allowFailure: true,
    timeoutMs: 10000,
  });
  await sleep(2000);
}

async function ensureEmulator(adbPath, emulatorPath, avdName) {
  let devices = await listEmulatorDevices(adbPath);
  let readyDevice = firstReadyEmulator(devices);

  if (readyDevice) {
    await ensureAdbDeviceReady(adbPath, readyDevice.serial);
    return readyDevice.serial;
  }

  if (devices.length > 0) {
    log(`Detected stale emulator state(s): ${summarizeEmulatorStates(devices)}. Attempting adb recovery...`);
    await restartAdbServer(adbPath);

    readyDevice = await waitForReadyEmulator(adbPath, 30000);
    if (readyDevice) {
      await ensureAdbDeviceReady(adbPath, readyDevice.serial);
      return readyDevice.serial;
    }

    log("Recovery did not produce a healthy emulator. Stopping stale emulator process(es).");
    await killStaleEmulators(adbPath, devices);
  }

  const avds = await listAvds(emulatorPath);
  if (!avds.includes(avdName)) {
    throw new Error(
      `AVD "${avdName}" was not found. Available AVDs: ${avds.join(", ") || "(none)"}`,
    );
  }

  log(`Starting emulator AVD "${avdName}"...`);
  const child = spawn(
    emulatorPath,
    ["-avd", avdName, "-netdelay", "none", "-netspeed", "full"],
    {
      detached: true,
      stdio: "ignore",
      windowsHide: false,
    },
  );
  child.unref();

  const launchedAt = Date.now();
  let restartedAdbDuringBoot = false;
  while (Date.now() - launchedAt <= 300000) {
    devices = await listEmulatorDevices(adbPath);
    readyDevice = firstReadyEmulator(devices);
    if (readyDevice) {
      await ensureAdbDeviceReady(adbPath, readyDevice.serial);
      return readyDevice.serial;
    }

    const stale = devices.find((item) => item.state === "offline" || item.state === "unauthorized");
    if (stale && !restartedAdbDuringBoot && Date.now() - launchedAt > 45000) {
      log(`Emulator still ${stale.state}; restarting adb server once during boot...`);
      await restartAdbServer(adbPath);
      restartedAdbDuringBoot = true;
    }

    await sleep(1000);
  }

  devices = await listEmulatorDevices(adbPath);
  throw new Error(`No emulator reached ready state. Last adb state(s): ${summarizeEmulatorStates(devices)}`);
}

async function adbShell(adbPath, serial, shellCommand, options = {}) {
  return adb(adbPath, ["-s", serial, "shell", shellCommand], options);
}

async function adbTap(adbPath, serial, x, y) {
  await adbShell(adbPath, serial, `input tap ${x} ${y}`, {
    timeoutMs: 10000,
  });
}

async function configureReverseProxy(adbPath, serial, reverseProxy) {
  if (!reverseProxy) {
    return null;
  }

  const deviceBinding = `tcp:${reverseProxy.devicePort}`;
  const hostBinding = `tcp:${reverseProxy.hostPort}`;
  await adb(adbPath, ["-s", serial, "reverse", "--remove", deviceBinding], {
    allowFailure: true,
    timeoutMs: 5000,
  });
  await adb(adbPath, ["-s", serial, "reverse", deviceBinding, hostBinding], {
    timeoutMs: 10000,
  });

  return {
    deviceBinding,
    hostBinding,
  };
}

function parseBounds(boundsText) {
  const match = String(boundsText || "").match(/\[(\d+),(\d+)\]\[(\d+),(\d+)\]/);
  if (!match) {
    return null;
  }

  const left = Number(match[1]);
  const top = Number(match[2]);
  const right = Number(match[3]);
  const bottom = Number(match[4]);

  return {
    left,
    top,
    right,
    bottom,
    centerX: Math.floor((left + right) / 2),
    centerY: Math.floor((top + bottom) / 2),
  };
}

function parseUiNodes(xml) {
  const nodes = [];
  const nodeRegex = /<node\b([^>]*?)\/>/g;
  let nodeMatch = nodeRegex.exec(xml);

  while (nodeMatch) {
    const rawAttributes = nodeMatch[1];
    const attributes = {};

    const attrRegex = /([:\w.-]+)="([^"]*)"/g;
    let attrMatch = attrRegex.exec(rawAttributes);
    while (attrMatch) {
      attributes[attrMatch[1]] = decodeXml(attrMatch[2]);
      attrMatch = attrRegex.exec(rawAttributes);
    }

    nodes.push(attributes);
    nodeMatch = nodeRegex.exec(xml);
  }

  return nodes;
}

function nodeLabel(node) {
  return [node.text, node["content-desc"], node["resource-id"]]
    .filter((part) => String(part || "").trim() !== "")
    .join(" ")
    .trim();
}

function findNodeByText(nodes, candidates) {
  const normalizedCandidates = candidates.map((value) => normalize(value)).filter(Boolean);
  if (normalizedCandidates.length === 0) {
    return null;
  }

  let exact = null;
  let contains = null;

  for (const node of nodes) {
    const label = normalize(nodeLabel(node));
    if (label === "") {
      continue;
    }

    if (!exact) {
      if (normalizedCandidates.some((candidate) => label === candidate)) {
        exact = node;
      }
    }

    if (!contains) {
      if (
        normalizedCandidates.some(
          (candidate) => label.includes(candidate) || candidate.includes(label),
        )
      ) {
        contains = node;
      }
    }

    if (exact && contains) {
      break;
    }
  }

  return exact || contains;
}

async function dumpUi(adbPath, serial) {
  await adb(adbPath, ["-s", serial, "shell", "uiautomator", "dump", "/sdcard/window_dump.xml"], {
    allowFailure: true,
    timeoutMs: 20000,
  });
  const xml = await adb(adbPath, ["-s", serial, "exec-out", "cat", "/sdcard/window_dump.xml"], {
    timeoutMs: 20000,
    allowFailure: true,
  });
  return String(xml.stdout || "");
}

async function tapNodeByText(adbPath, serial, candidates) {
  const xml = await dumpUi(adbPath, serial);
  if (!xml.includes("<node")) {
    return { tapped: false, matched: null };
  }

  const nodes = parseUiNodes(xml);
  const targetNode = findNodeByText(nodes, candidates);
  if (!targetNode) {
    return { tapped: false, matched: null };
  }

  const bounds = parseBounds(targetNode.bounds);
  if (!bounds) {
    return { tapped: false, matched: nodeLabel(targetNode) };
  }

  await adbTap(adbPath, serial, bounds.centerX, bounds.centerY);
  return {
    tapped: true,
    matched: nodeLabel(targetNode),
    bounds,
  };
}

async function openUrlInChrome(adbPath, serial, url) {
  await adb(adbPath, [
    "-s",
    serial,
    "shell",
    "am",
    "start",
    "-a",
    "android.intent.action.VIEW",
    "-n",
    `${DEFAULT_CHROME_PACKAGE}/com.google.android.apps.chrome.Main`,
    "-d",
    url,
  ], {
    timeoutMs: 15000,
  });
}

async function completeChromeOnboarding(adbPath, serial) {
  const onboardingCandidates = [
    ["Use without account", "Continue without an account", "Without an account"],
    ["No thanks", "Not now", "Skip", "Don't sign in", "Do not sign in"],
    ["Accept & continue", "Accept and continue"],
    ["Got it", "Dismiss", "Done", "Close"],
  ];

  const steps = [];
  let lastMatched = "";
  let repeatedMatches = 0;

  for (let i = 0; i < 10; i += 1) {
    let tappedThisIteration = false;

    for (const candidateGroup of onboardingCandidates) {
      const result = await tapNodeByText(adbPath, serial, candidateGroup);
      if (result.tapped) {
        tappedThisIteration = true;
        const matched = result.matched || candidateGroup[0];
        steps.push(matched);
        if (normalize(matched) === normalize(lastMatched)) {
          repeatedMatches += 1;
        } else {
          repeatedMatches = 0;
          lastMatched = matched;
        }

        await sleep(1200);
        break;
      }
    }

    if (repeatedMatches >= 2) {
      await adbShell(adbPath, serial, "input keyevent 4", {
        allowFailure: true,
        timeoutMs: 6000,
      });
      await sleep(700);
      break;
    }

    if (!tappedThisIteration) {
      break;
    }
  }

  return steps;
}

async function openChromeMenu(adbPath, serial) {
  let tappedMenu = await tapNodeByText(adbPath, serial, [
    "More options",
    "More",
    "Chrome menu",
  ]);

  if (!tappedMenu.tapped) {
    await adbShell(adbPath, serial, "input keyevent 82", {
      allowFailure: true,
      timeoutMs: 8000,
    });
    await sleep(600);
    tappedMenu = { tapped: true, matched: "KEYCODE_MENU" };
  } else {
    await sleep(500);
  }

  return tappedMenu;
}

async function listPackages(adbPath, serial) {
  const result = await adb(adbPath, ["-s", serial, "shell", "pm", "list", "packages"], {
    timeoutMs: 20000,
  });

  return result.stdout
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line.startsWith("package:"))
    .map((line) => line.replace(/^package:/, "").trim())
    .filter(Boolean);
}

function webApkPackages(packages) {
  return packages.filter((pkg) => normalize(pkg).includes("webapk"));
}

async function wakeAndUnlockDevice(adbPath, serial) {
  await adbShell(adbPath, serial, "input keyevent 224", {
    allowFailure: true,
    timeoutMs: 6000,
  });
  await adbShell(adbPath, serial, "wm dismiss-keyguard", {
    allowFailure: true,
    timeoutMs: 6000,
  });
  await adbShell(adbPath, serial, "input keyevent 82", {
    allowFailure: true,
    timeoutMs: 6000,
  });
  await sleep(500);
}

async function uninstallPackage(adbPath, serial, packageName) {
  await adb(adbPath, ["-s", serial, "uninstall", packageName], {
    allowFailure: true,
    timeoutMs: 15000,
  });
}

async function resetChromeInstallState(adbPath, serial) {
  const currentPackages = webApkPackages(await listPackages(adbPath, serial));
  for (const packageName of currentPackages) {
    await uninstallPackage(adbPath, serial, packageName);
  }

  await adb(adbPath, ["-s", serial, "shell", "pm", "clear", DEFAULT_CHROME_PACKAGE], {
    allowFailure: true,
    timeoutMs: 15000,
  });
  await sleep(1200);

  return currentPackages;
}

async function currentFocus(adbPath, serial) {
  const result = await adb(adbPath, ["-s", serial, "shell", "dumpsys", "window"], {
    timeoutMs: 30000,
    allowFailure: true,
    maxBuffer: 32 * 1024 * 1024,
  });

  const output = `${result.stdout}\n${result.stderr}`;
  const focusMatch = output.match(/mCurrentFocus=Window\{[^}]*\s([A-Za-z0-9._$]+)\/([A-Za-z0-9._$]+)\}/);
  if (focusMatch) {
    return {
      packageName: focusMatch[1],
      activityName: focusMatch[2],
      raw: focusMatch[0],
    };
  }

  const fallback = output.match(/mFocusedApp=ActivityRecord\{[^}]*\s([A-Za-z0-9._$]+)\/([A-Za-z0-9._$]+)\b/);
  if (fallback) {
    return {
      packageName: fallback[1],
      activityName: fallback[2],
      raw: fallback[0],
    };
  }

  return {
    packageName: "",
    activityName: "",
    raw: "",
  };
}

function isStandaloneFocus(focus) {
  if (!focus || !focus.packageName) {
    return false;
  }

  if (focus.packageName !== DEFAULT_CHROME_PACKAGE) {
    return true;
  }

  const activity = normalize(focus.activityName);
  if (activity === "" || activity.endsWith(".main")) {
    return false;
  }

  return activity.includes("webapp")
    || activity.includes("webapk")
    || activity.includes("h2o")
    || activity.includes("trustedwebactivity");
}

async function launchFromHomeShortcut(adbPath, serial) {
  await adbShell(adbPath, serial, "input keyevent 3", {
    allowFailure: true,
    timeoutMs: 6000,
  });
  await sleep(1400);

  return tapNodeByText(adbPath, serial, [
    "Egg1.3",
    "Real-Time Egg Monitoring System",
    "Egg1.3 Poultry Monitor",
    "Egg1",
  ]);
}

async function installFlow(adbPath, serial, pwaUrl, options = {}) {
  const {
    cleanInstall = true,
  } = options;

  let removedPackages = [];
  if (cleanInstall) {
    log("Resetting Chrome data and uninstalling existing WebAPKs for deterministic install flow...");
    removedPackages = await resetChromeInstallState(adbPath, serial);
  }

  const beforePackages = webApkPackages(await listPackages(adbPath, serial));

  await openUrlInChrome(adbPath, serial, pwaUrl);
  await sleep(2500);
  const onboardingSteps = await completeChromeOnboarding(adbPath, serial);
  await sleep(1200);

  let installMenuHit = null;
  for (let attempt = 0; attempt < 4; attempt += 1) {
    await openChromeMenu(adbPath, serial);
    const tappedInstall = await tapNodeByText(adbPath, serial, [
      "Install app",
      "Install",
      "Add to Home screen",
    ]);

    if (tappedInstall.tapped) {
      installMenuHit = tappedInstall;
      break;
    }

    await adbShell(adbPath, serial, "input keyevent 4", {
      allowFailure: true,
      timeoutMs: 6000,
    });
    await sleep(700);
  }

  if (!installMenuHit) {
    return {
      ok: false,
      onboardingSteps,
      message: "Chrome install menu item was not found.",
      cleanInstall,
      removedPackages,
      installMenuTapped: false,
      installConfirmTapped: false,
      openTapped: false,
      beforePackages,
      afterPackages: beforePackages,
      newPackages: [],
      standaloneLaunched: false,
      focusAfterLaunch: null,
    };
  }

  await sleep(1200);
  let installConfirmTapped = false;
  for (let i = 0; i < 5; i += 1) {
    const confirmTap = await tapNodeByText(adbPath, serial, [
      "Install",
      "Add",
      "Create",
    ]);
    if (confirmTap.tapped) {
      installConfirmTapped = true;
      break;
    }
    await sleep(700);
  }

  await sleep(1800);
  let openTapped = false;
  for (let i = 0; i < 4; i += 1) {
    const openTap = await tapNodeByText(adbPath, serial, ["Open"]);
    if (openTap.tapped) {
      openTapped = true;
      break;
    }
    await sleep(700);
  }

  const afterPackages = webApkPackages(await listPackages(adbPath, serial));
  const newPackages = afterPackages.filter((pkg) => !beforePackages.includes(pkg));

  let standaloneLaunchSource = "not-launched";
  if (openTapped) {
    standaloneLaunchSource = "open-button";
    await sleep(1800);
  }

  let focus = await currentFocus(adbPath, serial);
  let standaloneLaunched = isStandaloneFocus(focus);

  if (!standaloneLaunched && newPackages.length > 0) {
    await adb(adbPath, [
      "-s",
      serial,
      "shell",
      "monkey",
      "-p",
      newPackages[0],
      "-c",
      "android.intent.category.LAUNCHER",
      "1",
    ], {
      allowFailure: true,
      timeoutMs: 15000,
    });
    standaloneLaunchSource = `webapk:${newPackages[0]}`;
    await sleep(2200);
    focus = await currentFocus(adbPath, serial);
    standaloneLaunched = isStandaloneFocus(focus);
  }

  if (!standaloneLaunched) {
    const homeLaunch = await launchFromHomeShortcut(adbPath, serial);
    if (homeLaunch.tapped) {
      standaloneLaunchSource = "home-shortcut";
      await sleep(2200);
      focus = await currentFocus(adbPath, serial);
      standaloneLaunched = isStandaloneFocus(focus);
    }
  }

  const standaloneDetectedBy = standaloneLaunched
    ? "activity-check"
    : openTapped
      ? "open-button-heuristic"
      : "not-detected";
  const standaloneSatisfied = standaloneLaunched || openTapped;

  return {
    ok: installConfirmTapped && standaloneSatisfied,
    onboardingSteps,
    message: !installConfirmTapped
      ? "Install confirmation button was not found."
      : standaloneDetectedBy === "activity-check"
        ? "Install flow completed and standalone launch verified."
        : standaloneDetectedBy === "open-button-heuristic"
          ? "Install flow completed; standalone launch inferred from the Open action."
          : "Install completed but standalone launch was not detected.",
    cleanInstall,
    removedPackages,
    installMenuTapped: true,
    installConfirmTapped,
    openTapped,
    beforePackages,
    afterPackages,
    newPackages,
    standaloneLaunched: standaloneSatisfied,
    standaloneDetectedBy,
    standaloneLaunchSource,
    focusAfterLaunch: focus,
  };
}

async function findChromePage(browser, urlPrefix) {
  const startedAt = Date.now();
  while (Date.now() - startedAt <= 30000) {
    for (const context of browser.contexts()) {
      for (const page of context.pages()) {
        const pageUrl = page.url();
        if (pageUrl.startsWith(urlPrefix)) {
          return page;
        }
      }
    }
    await sleep(700);
  }
  return null;
}

function isExecutionContextDestroyed(error) {
  const message = error instanceof Error ? error.message : String(error);
  const normalized = message.toLowerCase();
  return normalized.includes("execution context was destroyed")
    || normalized.includes("cannot find context")
    || normalized.includes("most likely because of a navigation");
}

async function evaluateWithRetry(page, pageFunction, arg, maxAttempts = 5) {
  let lastError = null;
  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    try {
      if (typeof arg === "undefined") {
        return await page.evaluate(pageFunction);
      }
      return await page.evaluate(pageFunction, arg);
    } catch (error) {
      lastError = error;
      if (!isExecutionContextDestroyed(error)) {
        throw error;
      }
      await sleep(800);
    }
  }

  throw lastError || new Error("Failed to evaluate page script after retries.");
}

async function reloadPageWithRetry(page, maxAttempts = 3) {
  let lastError = null;
  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    try {
      await page.reload({ waitUntil: "networkidle", timeout: 45000 });
      return;
    } catch (error) {
      lastError = error;
      if (!isExecutionContextDestroyed(error)) {
        throw error;
      }
      await sleep(1200);
    }
  }

  if (lastError) {
    throw lastError;
  }
}

async function updateFlow({ adbPath, serial, emulatorBaseUrl }) {
  const swPath = path.join(process.cwd(), "public", "sw.js");
  const swSource = await fs.readFile(swPath, "utf8");
  const oldVersion = parseCacheVersion(swSource);
  if (!oldVersion) {
    throw new Error('Could not parse CACHE_VERSION from public/sw.js');
  }

  const newVersion = `egg13-pwa-emu-${Date.now()}`;
  const nextSwSource = swSource.replace(
    /const CACHE_VERSION = '[^']+';/,
    `const CACHE_VERSION = '${newVersion}';`,
  );

  if (nextSwSource === swSource) {
    throw new Error("Failed to patch CACHE_VERSION in public/sw.js");
  }

  let browser = null;
  let restored = false;

  try {
    await openUrlInChrome(adbPath, serial, `${emulatorBaseUrl}/login`);
    await sleep(2200);
    await completeChromeOnboarding(adbPath, serial);

    await adb(adbPath, ["forward", "--remove", "tcp:9222"], {
      allowFailure: true,
      timeoutMs: 5000,
    });
    await adb(adbPath, ["forward", "tcp:9222", "localabstract:chrome_devtools_remote"], {
      timeoutMs: 10000,
    });

    browser = await chromium.connectOverCDP("http://127.0.0.1:9222");
    let page = null;
    let runtimeInfo = null;
    for (let attempt = 0; attempt < 5; attempt += 1) {
      const candidate = await findChromePage(browser, emulatorBaseUrl);
      if (!candidate) {
        await openUrlInChrome(adbPath, serial, `${emulatorBaseUrl}/login`);
        await sleep(1800);
        continue;
      }

      page = candidate;
      await page.bringToFront().catch(() => {});
      await reloadPageWithRetry(page);

      runtimeInfo = await evaluateWithRetry(page, () => ({
        href: location.href,
        origin: location.origin,
        isSecureContext,
        hasServiceWorker: "serviceWorker" in navigator,
        hasCachesApi: typeof caches !== "undefined",
      }), undefined, 3).catch(() => null);

      if (
        runtimeInfo
        && runtimeInfo.href.startsWith(emulatorBaseUrl)
        && runtimeInfo.hasServiceWorker
        && runtimeInfo.hasCachesApi
      ) {
        break;
      }

      await openUrlInChrome(adbPath, serial, `${emulatorBaseUrl}/login`);
      await sleep(1800);
    }

    if (!page || !runtimeInfo || !runtimeInfo.href.startsWith(emulatorBaseUrl)) {
      throw new Error("Unable to locate a stable target Chrome tab over CDP.");
    }

    if (!runtimeInfo.hasServiceWorker) {
      throw new Error(
        `Service workers are unavailable on ${runtimeInfo.href} (secureContext=${runtimeInfo.isSecureContext}).`,
      );
    }
    if (!runtimeInfo.hasCachesApi) {
      throw new Error(`Cache API is unavailable on ${runtimeInfo.href}.`);
    }

    const serviceWorkerControlled = await evaluateWithRetry(page, async () => {
      if (!("serviceWorker" in navigator)) {
        return false;
      }

      await navigator.serviceWorker.ready;
      return Boolean(navigator.serviceWorker.controller);
    }, undefined, 5).catch(() => false);

    const beforeCacheKeys = await evaluateWithRetry(page, async () => {
      if (typeof caches === "undefined") {
        return [];
      }
      return caches.keys();
    });
    await fs.writeFile(swPath, nextSwSource, "utf8");

    let reloadCount = 0;
    page.on("framenavigated", (frame) => {
      if (frame === page.mainFrame()) {
        reloadCount += 1;
      }
    });

    const expectedKey = `${newVersion}-assets`;
    let cacheKeySwitched = false;

    for (let attempt = 0; attempt < 5 && !cacheKeySwitched; attempt += 1) {
      const activePage = await findChromePage(browser, emulatorBaseUrl);
      if (activePage) {
        page = activePage;
      }
      await page.bringToFront().catch(() => {});
      await reloadPageWithRetry(page);

      await sleep(1200);

      const loopRuntimeInfo = await evaluateWithRetry(page, () => ({
        href: location.href,
        hasServiceWorker: "serviceWorker" in navigator,
        hasCachesApi: typeof caches !== "undefined",
      }), undefined, 3).catch(() => null);

      if (
        !loopRuntimeInfo
        || !loopRuntimeInfo.href.startsWith(emulatorBaseUrl)
        || !loopRuntimeInfo.hasServiceWorker
        || !loopRuntimeInfo.hasCachesApi
      ) {
        await openUrlInChrome(adbPath, serial, `${emulatorBaseUrl}/login`);
        await sleep(1800);
        continue;
      }

      cacheKeySwitched = await evaluateWithRetry(page, async (cacheKey) => {
        if (typeof caches === "undefined") {
          return false;
        }
        const registration = await navigator.serviceWorker.getRegistration();
        if (registration) {
          await registration.update();
        }
        await navigator.serviceWorker.ready;
        const keys = await caches.keys();
        return keys.includes(cacheKey);
      }, expectedKey, 3).catch(() => false);

      if (!cacheKeySwitched) {
        cacheKeySwitched = await evaluateWithRetry(page, async (cacheKey) => {
          if (typeof caches === "undefined") {
            return false;
          }
          const keys = await caches.keys();
          return keys.includes(cacheKey);
        }, expectedKey, 3).catch(() => false);
      }
    }

    const afterCacheKeys = await evaluateWithRetry(page, async () => {
      if (typeof caches === "undefined") {
        return [];
      }
      return caches.keys();
    }, undefined, 3)
      .catch(() => []);

    return {
      ok: cacheKeySwitched,
      oldVersion,
      newVersion,
      expectedCacheKey: expectedKey,
      beforeCacheKeys,
      afterCacheKeys,
      cacheKeySwitched,
      reloadDetected: reloadCount > 0,
      reloadCount,
      serviceWorkerControlled,
    };
  } finally {
    const current = await fs.readFile(swPath, "utf8");
    if (current !== swSource) {
      await fs.writeFile(swPath, swSource, "utf8");
      restored = true;
    }

    if (browser) {
      await Promise.race([
        browser.close().catch(() => {}),
        sleep(5000),
      ]);
    }

    await adb(adbPath, ["forward", "--remove", "tcp:9222"], {
      allowFailure: true,
      timeoutMs: 5000,
    });

    if (restored) {
      log("Restored public/sw.js to original CACHE_VERSION.");
    }
  }
}

async function captureScreenshot(adbPath, serial, localPath) {
  await adb(adbPath, ["-s", serial, "shell", "screencap", "-p", "/sdcard/pwa-test-screen.png"], {
    allowFailure: true,
    timeoutMs: 10000,
  });
  await adb(adbPath, ["-s", serial, "pull", "/sdcard/pwa-test-screen.png", localPath], {
    allowFailure: true,
    timeoutMs: 10000,
  });
}

async function run() {
  const options = parseArgs();
  const tools = await resolveAndroidTools();
  const serial = await ensureEmulator(tools.adbPath, tools.emulatorPath, options.avd);
  await wakeAndUnlockDevice(tools.adbPath, serial);
  const reverseProxy = await configureReverseProxy(tools.adbPath, serial, options.reverseProxy);

  const emulatorBaseUrl = options.baseUrl.replace(/\/+$/, "");
  const report = {
    ok: false,
    avd: options.avd,
    serial,
    sourceBaseUrl: options.sourceUrl,
    emulatorBaseUrl,
    reverseProxy,
    options: {
      skipInstall: options.skipInstall,
      cleanInstall: options.cleanInstall,
    },
    installFlow: null,
    updateFlow: null,
    screenshotPath: path.join("tmp", "pwa-emulator-final.png"),
    timestamp: new Date().toISOString(),
  };

  try {
    if (!options.skipInstall) {
      log("Running install + standalone launch flow...");
      report.installFlow = await installFlow(tools.adbPath, serial, `${emulatorBaseUrl}/login`, {
        cleanInstall: options.cleanInstall,
      });
    } else {
      report.installFlow = {
        ok: true,
        skipped: true,
      };
    }

    log("Running service-worker update flow...");
    report.updateFlow = await updateFlow({
      adbPath: tools.adbPath,
      serial,
      emulatorBaseUrl,
    });

    const installOk = Boolean(report.installFlow?.ok);
    const updateOk = Boolean(report.updateFlow?.ok);
    report.ok = installOk && updateOk;
  } catch (error) {
    report.error = error instanceof Error ? error.message : String(error);
  } finally {
    await captureScreenshot(tools.adbPath, serial, report.screenshotPath);
  }

  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);

  if (!report.ok) {
    process.exit(1);
  }
}

run().catch((error) => {
  const message = error instanceof Error ? error.message : String(error);
  process.stderr.write(`[pwa-emulator-test] ERROR: ${message}\n`);
  process.exit(1);
});

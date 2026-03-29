import fs from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { TwaManifest } from "@bubblewrap/core";

const DEFAULT_BASE_URL = process.env.TWA_BASE_URL || "http://localhost/sumacot/egg1.3";
const DEFAULT_PACKAGE_ID = process.env.TWA_PACKAGE_ID || "ph.slsu.bontoc.egg13";
const DEFAULT_APP_NAME =
  process.env.TWA_APP_NAME || "Real-Time Egg Monitoring System";
const DEFAULT_LAUNCHER_NAME = process.env.TWA_LAUNCHER_NAME || "Egg1.3";
const DEFAULT_OUTPUT = process.env.TWA_MANIFEST_PATH || path.join("android", "twa", "twa-manifest.json");
const DEFAULT_KEYSTORE_ALIAS = process.env.TWA_KEYSTORE_ALIAS || "android";

function normalizeBaseUrl(raw) {
  const value = String(raw || "").trim();
  if (value === "") {
    throw new Error("Base URL is required.");
  }

  let parsed;
  try {
    parsed = new URL(value);
  } catch {
    throw new Error(`Invalid base URL: ${value}`);
  }

  return parsed.toString().replace(/\/+$/, "");
}

function parseArgs() {
  const args = process.argv.slice(2);
  const result = {
    baseUrl: normalizeBaseUrl(DEFAULT_BASE_URL),
    packageId: DEFAULT_PACKAGE_ID,
    appName: DEFAULT_APP_NAME,
    launcherName: DEFAULT_LAUNCHER_NAME,
    output: path.resolve(process.cwd(), DEFAULT_OUTPUT),
    keystorePath: "",
    keystoreAlias: DEFAULT_KEYSTORE_ALIAS,
    bumpVersion: false,
  };

  for (let i = 0; i < args.length; i += 1) {
    const arg = args[i];
    if ((arg === "--base-url" || arg === "-u") && args[i + 1]) {
      result.baseUrl = normalizeBaseUrl(args[i + 1]);
      i += 1;
      continue;
    }
    if ((arg === "--package-id" || arg === "-p") && args[i + 1]) {
      result.packageId = String(args[i + 1]).trim();
      i += 1;
      continue;
    }
    if ((arg === "--app-name" || arg === "-n") && args[i + 1]) {
      result.appName = String(args[i + 1]).trim();
      i += 1;
      continue;
    }
    if ((arg === "--launcher-name" || arg === "-l") && args[i + 1]) {
      result.launcherName = String(args[i + 1]).trim();
      i += 1;
      continue;
    }
    if ((arg === "--output" || arg === "-o") && args[i + 1]) {
      result.output = path.resolve(process.cwd(), args[i + 1]);
      i += 1;
      continue;
    }
    if ((arg === "--keystore-path" || arg === "-k") && args[i + 1]) {
      result.keystorePath = path.resolve(process.cwd(), args[i + 1]);
      i += 1;
      continue;
    }
    if ((arg === "--keystore-alias" || arg === "-a") && args[i + 1]) {
      result.keystoreAlias = String(args[i + 1]).trim();
      i += 1;
      continue;
    }
    if (arg === "--bump-version") {
      result.bumpVersion = true;
      continue;
    }
    if (arg === "--help" || arg === "-h") {
      process.stdout.write(
        [
          "Usage: node scripts/twa_prepare_manifest.mjs [options]",
          "",
          "Options:",
          "  --base-url, -u <url>          Base app URL (default: APP_URL or localhost).",
          "  --package-id, -p <id>         Android package id.",
          "  --app-name, -n <name>         Android app display name.",
          "  --launcher-name, -l <name>    Launcher label.",
          "  --output, -o <path>           Output twa-manifest.json path.",
          "  --keystore-path, -k <path>    Signing keystore path in manifest.",
          "  --keystore-alias, -a <alias>  Signing key alias in manifest.",
          "  --bump-version                Increment appVersionCode and appVersionName.",
        ].join("\n"),
      );
      process.exit(0);
    }
  }

  if (result.packageId === "") {
    throw new Error("Package id cannot be empty.");
  }
  if (result.appName === "") {
    throw new Error("App name cannot be empty.");
  }
  if (result.launcherName === "") {
    throw new Error("Launcher name cannot be empty.");
  }

  return result;
}

async function loadOrCreateManifest(options) {
  const manifestExists = await fs
    .access(options.output)
    .then(() => true)
    .catch(() => false);

  if (manifestExists) {
    return TwaManifest.fromFile(options.output);
  }

  const webManifestUrl = new URL("manifest.webmanifest", `${options.baseUrl}/`).toString();
  return TwaManifest.fromWebManifest(webManifestUrl);
}

function normalizeVersionFields(manifest) {
  const code = Number.isFinite(Number(manifest.appVersionCode))
    ? Number(manifest.appVersionCode)
    : 1;
  manifest.appVersionCode = code > 0 ? code : 1;

  const versionName = String(manifest.appVersionName || "").trim();
  manifest.appVersionName = versionName === "" ? String(manifest.appVersionCode) : versionName;
}

async function run() {
  const options = parseArgs();
  const manifest = await loadOrCreateManifest(options);

  manifest.name = options.appName;
  manifest.launcherName = options.launcherName;
  manifest.packageId = options.packageId;
  manifest.generatorApp = "bubblewrap-cli";
  manifest.enableNotifications = true;
  manifest.webManifestUrl = new URL("manifest.webmanifest", `${options.baseUrl}/`).toString();

  normalizeVersionFields(manifest);
  if (options.bumpVersion) {
    manifest.appVersionCode += 1;
    manifest.appVersionName = String(manifest.appVersionCode);
  }

  if (options.keystorePath) {
    manifest.signingKey = manifest.signingKey || {};
    manifest.signingKey.path = options.keystorePath;
    manifest.signingKey.alias = options.keystoreAlias;
  }

  await fs.mkdir(path.dirname(options.output), { recursive: true });
  await manifest.saveToFile(options.output);

  process.stdout.write(
    `${JSON.stringify(
      {
        ok: true,
        manifestPath: options.output,
        packageId: manifest.packageId,
        appVersionCode: manifest.appVersionCode,
        appVersionName: manifest.appVersionName,
        host: manifest.host,
        startUrl: manifest.startUrl,
      },
      null,
      2,
    )}\n`,
  );
}

run().catch((error) => {
  const message = error instanceof Error ? error.message : String(error);
  process.stderr.write(`[twa-prepare-manifest] ERROR: ${message}\n`);
  process.exit(1);
});

/**
 * Module resolution hook for the `scripts/*.mts` checks.
 *
 * Next.js resolves `@/…` aliases and extensionless `./foo` specifiers through
 * its bundler. Plain `node --experimental-strip-types` does neither, so this
 * hook teaches it both — letting the scripts import the real application
 * sources instead of a duplicated copy.
 */
import { existsSync } from "node:fs";
import { fileURLToPath, pathToFileURL } from "node:url";
import path from "node:path";

const SRC_ROOT = path.resolve(fileURLToPath(import.meta.url), "../../src");
const EXTENSIONS = [".ts", ".tsx", ".mts", ".js"];

function firstExisting(basePath) {
  if (existsSync(basePath) && path.extname(basePath)) return basePath;
  for (const ext of EXTENSIONS) {
    const candidate = `${basePath}${ext}`;
    if (existsSync(candidate)) return candidate;
  }
  for (const ext of EXTENSIONS) {
    const candidate = path.join(basePath, `index${ext}`);
    if (existsSync(candidate)) return candidate;
  }
  return null;
}

export async function resolve(specifier, context, nextResolve) {
  // "@/lib/domain/types" -> "<repo>/src/lib/domain/types.ts"
  if (specifier.startsWith("@/")) {
    const resolved = firstExisting(path.join(SRC_ROOT, specifier.slice(2)));
    if (resolved) {
      return { url: pathToFileURL(resolved).href, shortCircuit: true };
    }
  }

  if (specifier.startsWith("./") || specifier.startsWith("../")) {
    try {
      return await nextResolve(specifier, context);
    } catch (err) {
      if (err?.code !== "ERR_MODULE_NOT_FOUND" || !context.parentURL) throw err;
      const base = path.resolve(
        path.dirname(fileURLToPath(context.parentURL)),
        specifier,
      );
      const resolved = firstExisting(base);
      if (!resolved) throw err;
      return { url: pathToFileURL(resolved).href, shortCircuit: true };
    }
  }

  return nextResolve(specifier, context);
}

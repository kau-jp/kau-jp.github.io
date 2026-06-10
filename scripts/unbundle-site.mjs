import { gunzipSync } from "node:zlib";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { extname } from "node:path";

const pages = ["home.html", "about.html", "products.html", "news.html"];
const mimeExtensions = {
  "application/javascript": ".js",
  "font/woff2": ".woff2",
  "image/jpeg": ".jpg",
  "image/png": ".png",
  "image/svg+xml": ".svg",
  "image/webp": ".webp",
  "text/css": ".css",
  "text/javascript": ".js",
};

await mkdir("assets", { recursive: true });

for (const page of pages) {
  const source = await readFile(page, "utf8");
  const manifestMatch = source.match(
    /<script type="__bundler\/manifest">\s*([\s\S]*?)\s*<\/script>/,
  );
  const templateMatch = source.match(
    /<script type="__bundler\/template">\s*([\s\S]*?)\s*<\/script>/,
  );
  const resourcesMatch = source.match(
    /<script type="__bundler\/ext_resources">\s*([\s\S]*?)\s*<\/script>/,
  );

  if (!manifestMatch || !templateMatch) {
    console.log(`${page}: already unpacked`);
    continue;
  }

  const manifest = JSON.parse(manifestMatch[1]);
  const externalResources = resourcesMatch
    ? JSON.parse(resourcesMatch[1])
    : [];
  let template = JSON.parse(templateMatch[1]);
  const assetPaths = {};

  for (const [uuid, entry] of Object.entries(manifest)) {
    const extension = mimeExtensions[entry.mime] || extname(uuid) || ".bin";
    const assetPath = `assets/${uuid}${extension}`;
    const encoded = Buffer.from(entry.data, "base64");
    const bytes = entry.compressed ? gunzipSync(encoded) : encoded;
    await writeFile(assetPath, bytes);
    assetPaths[uuid] = assetPath;
    template = template.split(uuid).join(assetPath);
  }

  const resourceMap = {};
  for (const entry of externalResources) {
    if (assetPaths[entry.uuid]) resourceMap[entry.id] = assetPaths[entry.uuid];
  }

  const resourceScript =
    `<script>window.__resources=${JSON.stringify(resourceMap)};</script>`;
  template = template.replace(/<head([^>]*)>/i, `<head$1>${resourceScript}`);
  template = template.replace(
    /<\/body>/i,
    `<script src="cms-content.js" defer></script></body>`,
  );

  await writeFile(page, template, "utf8");
  console.log(`${page}: unpacked`);
}

/**
 * 512x512 のソースアイコンから各サイズの PNG を生成
 * icon-512.png が無い場合はアプリブランドのフォールバックアイコンを生成
 */
import sharp from "sharp";
import { readFileSync, existsSync, writeFileSync } from "fs";
import { dirname, join } from "path";
import { fileURLToPath } from "url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const SIZES = [
  { name: "icon-512.png", size: 512 },
  { name: "icon-192.png", size: 192 },
  { name: "apple-touch-icon.png", size: 180 },
  { name: "apple-touch-icon-152.png", size: 152 },
  { name: "apple-touch-icon-144.png", size: 144 },
  { name: "apple-touch-icon-120.png", size: 120 },
  { name: "apple-touch-icon-114.png", size: 114 },
  { name: "apple-touch-icon-76.png", size: 76 },
  { name: "apple-touch-icon-72.png", size: 72 },
  { name: "apple-touch-icon-60.png", size: 60 },
  { name: "apple-touch-icon-57.png", size: 57 },
  { name: "favicon-32x32.png", size: 32 },
  { name: "favicon-16x16.png", size: 16 },
];

const sourcePath = join(__dirname, "icon-512.png");

/** icon-512.png が無い場合のフォールバック（肌×AIをイメージしたSVG） */
function createFallbackIcon() {
  const svg = `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#6366f1"/>
      <stop offset="100%" style="stop-color:#a855f7"/>
    </linearGradient>
    <linearGradient id="face" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#fef3c7;stop-opacity:0.95"/>
      <stop offset="100%" style="stop-color:#fde68a;stop-opacity:0.9"/>
    </linearGradient>
  </defs>
  <rect width="512" height="512" fill="url(#bg)" rx="96"/>
  <path fill="url(#face)" d="M180 140 Q120 140 90 200 Q70 250 100 300 Q130 350 180 380 L332 380 Q382 350 412 300 Q442 250 422 200 Q392 140 332 140 Q280 140 256 180 Q232 140 180 140 Z"/>
  <ellipse cx="200" cy="240" rx="18" ry="22" fill="#6366f1" opacity="0.3"/>
  <ellipse cx="312" cy="240" rx="18" ry="22" fill="#6366f1" opacity="0.3"/>
  <path d="M220 300 Q256 320 292 300" stroke="#a855f7" stroke-width="8" fill="none" stroke-linecap="round" opacity="0.5"/>
  <circle cx="256" cy="120" r="6" fill="white" opacity="0.9"/>
  <circle cx="180" cy="200" r="4" fill="white" opacity="0.6"/>
  <circle cx="332" cy="200" r="4" fill="white" opacity="0.6"/>
</svg>`;
  return Buffer.from(svg);
}

async function generate() {
  let buffer;
  if (existsSync(sourcePath)) {
    buffer = readFileSync(sourcePath);
    console.log("Using icon-512.png");
  } else {
    console.log("icon-512.png not found. Generating fallback icon...");
    buffer = await sharp(createFallbackIcon()).resize(512, 512).png().toBuffer();
    writeFileSync(sourcePath, buffer);
    console.log("Created icon-512.png (fallback)");
  }
  for (const { name, size } of SIZES) {
    const outPath = join(__dirname, name);
    await sharp(buffer)
      .resize(size, size)
      .png()
      .toFile(outPath);
    console.log(`Generated: ${name} (${size}x${size})`);
  }
  console.log("Done.");
}

generate().catch((e) => {
  console.error("[generate-png-icons]", e.message || e);
  process.exit(1);
});

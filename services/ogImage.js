const { createCanvas } = require('@napi-rs/canvas');
const fs = require('fs');
const path = require('path');

const OG_WIDTH = 1200;
const OG_HEIGHT = 630;
const PADDING_X = 80;
const PADDING_Y = 60;
const LINE_HEIGHT_RATIO = 1.2;

const COLORS = {
  background: '#292929',
  title: '#ffffff',
  byline: '#aaaaaa',
  accent: '#1a8917'
};

function wrapText(ctx, text, maxWidth) {
  const words = text.split(' ');
  const lines = [];
  let currentLine = words[0];

  for (let i = 1; i < words.length; i++) {
    const word = words[i];
    const width = ctx.measureText(currentLine + ' ' + word).width;
    if (width < maxWidth) {
      currentLine += ' ' + word;
    } else {
      lines.push(currentLine);
      currentLine = word;
    }
  }
  lines.push(currentLine);
  return lines;
}

function pickFontSize(ctx, text, maxWidth, maxHeight, baseSize) {
  let size = baseSize;
  ctx.font = `bold ${size}px sans-serif`;
  const lines = wrapText(ctx, text, maxWidth);
  const lineHeight = size * LINE_HEIGHT_RATIO;
  const totalHeight = lines.length * lineHeight;

  if (totalHeight > maxHeight && size > 28) {
    // Reduce font size and try again
    return pickFontSize(ctx, text, maxWidth, maxHeight, size - 4);
  }
  return { size, lines, lineHeight, totalHeight };
}

async function generateOGImage(title, author, outputPath) {
  const canvas = createCanvas(OG_WIDTH, OG_HEIGHT);
  const ctx = canvas.getContext('2d');

  // Background
  ctx.fillStyle = COLORS.background;
  ctx.fillRect(0, 0, OG_WIDTH, OG_HEIGHT);

  // Subtle top accent bar
  ctx.fillStyle = COLORS.accent;
  ctx.fillRect(0, 0, OG_WIDTH, 4);

  const maxTextWidth = OG_WIDTH - (PADDING_X * 2);
  const bylineText = author ? `by ${author}` : '';

  // Measure byline first so we know vertical space
  const bylineFontSize = 24;
  const bylineLineHeight = bylineFontSize * LINE_HEIGHT_RATIO;
  const bylineHeight = bylineText ? bylineLineHeight + 40 : 0; // 40px gap between title and byline

  // Available height for title
  const availableTitleHeight = OG_HEIGHT - (PADDING_Y * 2) - bylineHeight;

  // Pick font size and wrap title
  const { size: titleFontSize, lines, lineHeight, totalHeight } = pickFontSize(
    ctx,
    title,
    maxTextWidth,
    availableTitleHeight,
    60
  );

  // Total block height (title + gap + byline)
  const blockHeight = totalHeight + bylineHeight;
  let startY = (OG_HEIGHT - blockHeight) / 2;

  // Draw title lines
  ctx.fillStyle = COLORS.title;
  ctx.font = `bold ${titleFontSize}px sans-serif`;
  ctx.textBaseline = 'top';

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const metrics = ctx.measureText(line);
    const x = (OG_WIDTH - metrics.width) / 2;
    const y = startY + (i * lineHeight);
    ctx.fillText(line, x, y);
  }

  // Draw byline
  if (bylineText) {
    ctx.fillStyle = COLORS.byline;
    ctx.font = `${bylineFontSize}px sans-serif`;
    const bylineMetrics = ctx.measureText(bylineText);
    const bylineX = (OG_WIDTH - bylineMetrics.width) / 2;
    const bylineY = startY + totalHeight + 40;
    ctx.fillText(bylineText, bylineX, bylineY);
  }

  const buffer = await canvas.encode('png');

  if (outputPath) {
    const dir = path.dirname(outputPath);
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
    }
    fs.writeFileSync(outputPath, buffer);
  }

  return buffer;
}

function getCachePath(slug) {
  return path.join(__dirname, '..', 'public', 'og-images', `${slug}.png`);
}

function clearCache(slug) {
  const cachePath = getCachePath(slug);
  if (fs.existsSync(cachePath)) {
    fs.unlinkSync(cachePath);
  }
}

module.exports = {
  generateOGImage,
  getCachePath,
  clearCache
};

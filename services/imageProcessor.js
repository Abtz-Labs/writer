const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');
const { v4: uuidv4 } = require('uuid');
const { pipeline } = require('stream/promises');

const SUPPORTED_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.gif', '.webp'];
const IMAGE_URL_REGEX = /https?:\/\/[^)\s"']+\.(jpg|jpeg|png|gif|webp)(\?[^)\s"']*)?/gi;
const MAX_REDIRECTS = 5;

function resolveExtension(contentType, url) {
  if (contentType.includes('png')) return '.png';
  if (contentType.includes('gif')) return '.gif';
  if (contentType.includes('webp')) return '.webp';
  if (contentType.includes('jpeg') || contentType.includes('jpg')) return '.jpg';

  try {
    const urlExt = path.extname(new URL(url).pathname);
    if (SUPPORTED_EXTENSIONS.includes(urlExt)) {
      return urlExt;
    }
  } catch {
    // ignore malformed URL
  }
  return '.jpg';
}

async function downloadImage(url, uploadDir, redirectCount = 0) {
  if (redirectCount > MAX_REDIRECTS) {
    throw new Error('Too many redirects');
  }

  // Ensure upload directory exists
  fs.mkdirSync(uploadDir, { recursive: true });

  return new Promise((resolve, reject) => {
    const protocol = url.startsWith('https') ? https : http;

    const timeout = setTimeout(() => {
      reject(new Error('Download timeout'));
    }, 15000);

    protocol.get(url, { headers: { 'User-Agent': 'Mozilla/5.0' } }, (response) => {
      // Handle redirects
      if (response.statusCode >= 300 && response.statusCode < 400 && response.headers.location) {
        clearTimeout(timeout);
        const redirectUrl = new URL(response.headers.location, url).toString();
        downloadImage(redirectUrl, uploadDir, redirectCount + 1)
          .then(resolve)
          .catch(reject);
        return;
      }

      if (response.statusCode !== 200) {
        clearTimeout(timeout);
        reject(new Error(`HTTP ${response.statusCode}`));
        return;
      }

      const contentType = response.headers['content-type'] || '';
      const extension = resolveExtension(contentType, url);
      const filename = `${uuidv4()}${extension}`;
      const filepath = path.join(uploadDir, filename);

      const writeStream = fs.createWriteStream(filepath);

      response.pipe(writeStream);

      writeStream.on('finish', () => {
        clearTimeout(timeout);
        resolve(`/uploads/${filename}`);
      });

      writeStream.on('error', (err) => {
        clearTimeout(timeout);
        // Clean up partial file
        try { fs.unlinkSync(filepath); } catch {}
        reject(err);
      });

      response.on('error', (err) => {
        clearTimeout(timeout);
        writeStream.destroy();
        try { fs.unlinkSync(filepath); } catch {}
        reject(err);
      });
    }).on('error', (err) => {
      clearTimeout(timeout);
      reject(err);
    });
  });
}

async function processImages(body, uploadDir) {
  if (!body) return body;

  const urls = body.match(IMAGE_URL_REGEX);

  if (!urls || urls.length === 0) {
    return body;
  }

  let processedBody = body;
  const replacements = [];

  for (const url of urls) {
    try {
      const localPath = await downloadImage(url, uploadDir);
      replacements.push({ original: url, replacement: localPath });
    } catch (err) {
      console.warn(`Failed to download image: ${url}`, err.message);
    }
  }

  for (const { original, replacement } of replacements) {
    // Escape regex special characters in the original URL
    const escaped = original.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    processedBody = processedBody.replace(new RegExp(escaped, 'g'), replacement);
  }

  return processedBody;
}

function findImageUrls(text) {
  const urls = text.match(IMAGE_URL_REGEX);
  return urls || [];
}

module.exports = {
  processImages,
  findImageUrls,
  downloadImage,
  SUPPORTED_EXTENSIONS,
  IMAGE_URL_REGEX
};

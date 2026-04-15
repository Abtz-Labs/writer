const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');
const { v4: uuidv4 } = require('uuid');
const { pipeline } = require('stream/promises');

const SUPPORTED_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.gif', '.webp'];
const IMAGE_URL_REGEX = /https?:\/\/[^)\s"']+\.(jpg|jpeg|png|gif|webp)(\?[^)\s"']*)?/gi;

async function downloadImage(url, uploadDir) {
  return new Promise((resolve, reject) => {
    const protocol = url.startsWith('https') ? https : http;
    
    const timeout = setTimeout(() => {
      reject(new Error('Download timeout'));
    }, 10000);
    
    protocol.get(url, (response) => {
      if (response.statusCode !== 200) {
        clearTimeout(timeout);
        reject(new Error(`HTTP ${response.statusCode}`));
        return;
      }
      
      const contentType = response.headers['content-type'] || '';
      let extension = '.jpg';
      
      if (contentType.includes('png')) extension = '.png';
      else if (contentType.includes('gif')) extension = '.gif';
      else if (contentType.includes('webp')) extension = '.webp';
      else if (!contentType.includes('jpeg') && !contentType.includes('jpg')) {
        const urlExt = path.extname(new URL(url).pathname);
        if (SUPPORTED_EXTENSIONS.includes(urlExt)) {
          extension = urlExt;
        }
      }
      
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
    processedBody = processedBody.replace(original, replacement);
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
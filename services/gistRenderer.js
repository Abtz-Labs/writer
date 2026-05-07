const https = require("https");
const hljs = require("highlight.js");

const CACHE = new Map();
const CACHE_TTL_MS = 1000 * 60 * 60; // 1 hour

function escapeHtml(text) {
  if (!text) return "";
  return String(text)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function extractGistId(url) {
  const match = url.match(
    /gist\.github\.com\/[^/]+\/([a-f0-9]+)(?:\.js)?(?:\?.*)?$/i,
  );
  return match ? match[1] : null;
}

function fetchJson(url) {
  return new Promise((resolve, reject) => {
    const req = https.get(
      url,
      {
        headers: {
          "User-Agent": "Writer/1.0",
          Accept: "application/vnd.github.v3+json",
        },
        timeout: 10000,
      },
      (res) => {
        let data = "";
        res.setEncoding("utf8");
        res.on("data", (chunk) => {
          data += chunk;
        });
        res.on("end", () => {
          if (res.statusCode >= 200 && res.statusCode < 300) {
            try {
              resolve(JSON.parse(data));
            } catch (e) {
              reject(new Error(`Invalid JSON from ${url}: ${e.message}`));
            }
          } else {
            reject(
              new Error(
                `HTTP ${res.statusCode} from ${url}: ${data.substring(0, 200)}`,
              ),
            );
          }
        });
      },
    );
    req.on("error", reject);
    req.on("timeout", () => {
      req.destroy();
      reject(new Error(`Timeout fetching ${url}`));
    });
  });
}

async function fetchGist(gistId) {
  const cached = CACHE.get(gistId);
  if (cached && Date.now() - cached.timestamp < CACHE_TTL_MS) {
    return cached.data;
  }

  const data = await fetchJson(`https://api.github.com/gists/${gistId}`);
  CACHE.set(gistId, { data, timestamp: Date.now() });
  return data;
}

function highlightCode(content, language) {
  if (language && hljs.getLanguage(language)) {
    return hljs.highlight(content, { language }).value;
  }
  return hljs.highlightAuto(content).value;
}

function renderFile(filename, content, language, gistUrl) {
  const highlighted = highlightCode(content, language);
  const langClass = language ? `language-${language.toLowerCase()}` : "";

  return `
    <div class="gist-file">
      <div class="gist-file-header">
        <svg class="gist-icon" viewBox="0 0 16 16" width="16" height="16" fill="currentColor" aria-hidden="true">
          <path d="M1 2.5A2.5 2.5 0 0 1 3.5 0h8.75a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-.75.75h-8.5A1.75 1.75 0 0 0 3 6.75v6.5a1.75 1.75 0 0 0 1.75 1.75h8.5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-.75.75h-8.75A2.5 2.5 0 0 1 1 13.5v-11Z"/>
          <path d="M3 3.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5ZM3 5.5a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5ZM3 7.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5ZM3 9.5a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5ZM3 11.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5Z"/>
        </svg>
        <span class="gist-filename">${escapeHtml(filename)}</span>
        <a href="${escapeHtml(gistUrl)}" target="_blank" rel="noopener noreferrer" class="gist-link">View on GitHub &rarr;</a>
      </div>
      <div class="gist-file-body">
        <pre><code class="hljs ${langClass}">${highlighted}</code></pre>
      </div>
    </div>
  `;
}

async function renderGistsInHtml(html) {
  const scriptRegex =
    /<script[^>]+src=["'](https:\/\/gist\.github\.com\/[^"']+)["'][^>]*><\/script>/gi;
  const matches = Array.from(html.matchAll(scriptRegex));

  if (matches.length === 0) {
    return html;
  }

  let result = html;

  for (const match of matches) {
    const [fullTag, url] = match;
    const gistId = extractGistId(url);

    if (!gistId) continue;

    try {
      const gist = await fetchGist(gistId);
      const files = Object.values(gist.files || {});

      if (files.length === 0) continue;

      const gistUrl = gist.html_url || `https://gist.github.com/${gistId}`;

      const renderedFiles = files
        .map((file) =>
          renderFile(file.filename, file.content, file.language, gistUrl),
        )
        .join("");

      const wrapper = `<div class="gist-embed">${renderedFiles}</div>`;
      result = result.replace(fullTag, wrapper);
    } catch (err) {
      console.error(`Failed to render gist ${gistId}:`, err.message);
      // Replace with a subtle fallback pointing to the gist
      result = result.replace(
        fullTag,
        `
        <div class="gist-embed gist-error">
          <p>Unable to load gist. <a href="https://gist.github.com/${gistId}" target="_blank" rel="noopener noreferrer">View it on GitHub &rarr;</a></p>
        </div>
      `,
      );
    }
  }

  return result;
}

module.exports = { renderGistsInHtml };

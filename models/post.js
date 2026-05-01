const { v4: uuidv4 } = require('uuid');
const { marked } = require('marked');
const xss = require('xss');

const GIST_DOMAIN = 'gist.github.com';

function stripNonGistScripts(html) {
  return html.replace(/<script[\s\S]*?<\/script>/gi, (match) => {
    const srcMatch = match.match(/src=["']([^"']+)["']/i);
    
    if (srcMatch) {
      try {
        const url = new URL(srcMatch[1]);
        
        if (url.hostname === GIST_DOMAIN && url.protocol === 'https:') {
          return match;
        }
      } catch {
        // invalid URL
      }
    }
    
    return '';
  });
}

const xssOptions = {
  whiteList: {
    ...xss.getDefaultWhiteList(),
    script: ['src']
  },
  safeAttrValue(tag, name, value, cssFilter) {
    if (tag === 'script' && name === 'src') {
      try {
        const url = new URL(value);
        
        if (url.hostname === GIST_DOMAIN && url.protocol === 'https:') {
          return value;
        }
      } catch {
        // invalid URL
      }
      return '';
    }
    
    return xss.safeAttrValue(tag, name, value, cssFilter);
  }
};

const sanitizeHtml = new xss.FilterXSS(xssOptions);

class Post {
  constructor(data) {
    this.id = data.id || uuidv4();
    this.title = data.title;
    this.slug = data.slug;
    this.body = data.body;
    this.excerpt = data.excerpt;
    this.cover_image = data.cover_image || '';
    this.keywords = data.keywords;
    this.meta_description = data.meta_description;
    this.reading_time = data.reading_time;
    this.status = data.status || 'draft';
    this.tags = data.tags || [];
    this.created_at = data.created_at || new Date().toISOString();
    this.updated_at = data.updated_at || new Date().toISOString();
  }

  static fromDB(obj) {
    return new Post(obj);
  }

  static extractFirstImage(body, coverImage = '') {
    if (!body) return coverImage || '';

    // Markdown image: ![alt](url) or ![alt](url "title")
    const mdMatch = body.match(/!\[.*?\]\((.*?)(?:\s+["'].*?["'])?\)/);
    if (mdMatch) return mdMatch[1];

    // Raw HTML image: <img src="url">
    const htmlMatch = body.match(/<img[^>]+src=["']([^"']+)["']/i);
    if (htmlMatch) return htmlMatch[1];

    return coverImage || '';
  }

  toJSON() {
    return {
      id: this.id,
      title: this.title,
      slug: this.slug,
      body: this.body,
      excerpt: this.excerpt,
      cover_image: this.cover_image,
      keywords: this.keywords,
      meta_description: this.meta_description,
      reading_time: this.reading_time,
      status: this.status,
      tags: this.tags,
      created_at: this.created_at,
      updated_at: this.updated_at
    };
  }

  toAdminJSON() {
    return this.toJSON();
  }

  toApiJSON() {
    const json = this.toJSON();
    return {
      ...json,
      bodyHtml: sanitizeHtml.process(stripNonGistScripts(marked.parse(this.body, { breaks: true, gfm: true })))
    };
  }

  toView() {
    const json = this.toJSON();

    // Regenerate a clean plain-text excerpt from the body so that
    // any markdown tags missed when the post was originally saved
    // are stripped at display time.
    let cleanExcerpt = '';
    if (this.body) {
      const plain = this.body
        .replace(/<script[\s\S]*?<\/script>/gi, '')
        .replace(/!\[.*?\]\(.*?\)/g, '')
        .replace(/\[.*?\]\(.*?\)/g, '')
        .replace(/~~[\s\S]+?~~/g, '')
        .replace(/~[\s\S]+?~/g, '')
        .replace(/[#*_`~]/g, '')
        .replace(/\n+/g, ' ')
        .trim();
      cleanExcerpt = plain.length <= 200
        ? plain
        : plain.substring(0, 197).trim() + '...';
    }

    return {
      ...json,
      excerpt: cleanExcerpt,
      bodyHtml: sanitizeHtml.process(stripNonGistScripts(marked.parse(this.body, { breaks: true, gfm: true }))),
      isPublished: this.status === 'published',
      firstImage: Post.extractFirstImage(this.body, this.cover_image)
    };
  }
}

module.exports = Post;
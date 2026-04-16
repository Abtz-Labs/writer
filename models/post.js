const { v4: uuidv4 } = require('uuid');
const { marked } = require('marked');

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
      bodyHtml: marked.parse(this.body, { breaks: true, gfm: true })
    };
  }

  toView() {
    const json = this.toJSON();
    return {
      ...json,
      bodyHtml: marked.parse(this.body, { breaks: true, gfm: true }),
      isPublished: this.status === 'published'
    };
  }
}

module.exports = Post;
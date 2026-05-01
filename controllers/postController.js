const { getCollection, forceSavePost } = require('../config/database');
const Post = require('../models/post');
const metadata = require('../services/metadata');
const imageProcessor = require('../services/imageProcessor');
const confirmationService = require('../services/confirmation');
const ogImage = require('../services/ogImage');
const { marked } = require('marked');
const path = require('path');
const logger = require('../utils/logger');

marked.setOptions({
  breaks: true,
  gfm: true
});

function validatePostInput(data, isUpdate = false) {
  const { title, body, tags, status } = data;
  const errors = [];

  if (!isUpdate || title !== undefined) {
    if (!isUpdate && (!title || typeof title !== 'string' || title.trim().length === 0)) {
      errors.push('Title is required and must be a non-empty string');
    }
    if (title && title.length > 200) {
      errors.push('Title must not exceed 200 characters');
    }
  }

  if (!isUpdate || body !== undefined) {
    if (!isUpdate && (!body || typeof body !== 'string' || body.trim().length === 0)) {
      errors.push('Body is required and must be a non-empty string');
    }
    if (body && body.length > 50000) {
      errors.push('Body must not exceed 50000 characters');
    }
  }

  if (tags !== undefined) {
    if (!Array.isArray(tags) || !tags.every(t => typeof t === 'string' && t.length <= 50)) {
      errors.push('Tags must be an array of strings, each not exceeding 50 characters');
    }
  }

  if (status !== undefined && !['draft', 'published'].includes(status)) {
    errors.push('Status must be either "draft" or "published"');
  }

  return errors;
}

class PostController {
  async getAll(req, res, next) {
    try {
      const postsCollection = getCollection('posts');
      const postsData = await postsCollection.find();
      const posts = (postsData || [])
        .map(p => Post.fromDB(p).toApiJSON())
        .filter(p => p.status === 'published')
        .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

      const page = Math.max(1, parseInt(req.query.page) || 1);
      const limit = Math.min(Math.max(1, parseInt(req.query.limit) || 10), 100);
      const start = (page - 1) * limit;
      const end = start + limit;

      const paginatedPosts = posts.slice(start, end);

      res.json({
        posts: paginatedPosts,
        pagination: {
          page,
          limit,
          total: posts.length,
          pages: Math.ceil(posts.length / limit)
        }
      });
    } catch (err) {
      next(err);
    }
  }
  
  async getBySlug(req, res, next) {
    try {
      const postsCollection = getCollection('posts');
      const allPosts = await postsCollection.find() || [];
      const post = allPosts.find(p => p.slug === req.params.slug);

      if (!post || (post.status !== 'published' && !req.settings)) {
        return res.status(404).json({
          error: 'Not Found',
          message: 'Post not found'
        });
      }

      res.json(Post.fromDB(post).toApiJSON());
    } catch (err) {
      next(err);
    }
  }
  
  async create(req, res, next) {
    try {
      const { title, body, tags, cover_image, status } = req.body;

      const validationErrors = validatePostInput(req.body);
      if (validationErrors.length > 0) {
        return res.status(400).json({
          error: 'Validation Error',
          message: validationErrors.join('; ')
        });
      }
      
      const postsCollection = getCollection('posts');
      
      const uploadDir = path.join(__dirname, '../../public/uploads');
      
      let processedBody = body;
      let processedCoverImage = cover_image || '';
      
      try {
        processedBody = await imageProcessor.processImages(body, uploadDir);
        if (cover_image && cover_image.startsWith('http')) {
          try {
            processedCoverImage = await imageProcessor.downloadImage(cover_image, uploadDir);
          } catch (imgErr) {
            logger.warn('Cover image download failed:', imgErr.message);
          }
        }
      } catch (imgErr) {
        logger.warn('Image processing failed:', imgErr.message);
      }
      
      const allPosts = await postsCollection.find() || [];
      const inferred = metadata.inferMetadata(title, processedBody);
      const slug = metadata.generateUniqueSlugFromList(allPosts, inferred.slug);
      
      const postData = {
        id: require('uuid').v4(),
        title,
        slug,
        body: processedBody,
        cover_image: processedCoverImage,
        excerpt: inferred.excerpt,
        keywords: inferred.keywords,
        meta_description: inferred.meta_description,
        reading_time: inferred.reading_time,
        status: status || 'draft',
        tags: tags || [],
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString()
      };
      
      await postsCollection.insert(postData);
      
      const post = Post.fromDB(postData);
      res.status(201).json(post.toApiJSON());
    } catch (err) {
      next(err);
    }
  }
  
  async update(req, res, next) {
    try {
      const postsCollection = getCollection('posts');
      const allPosts = await postsCollection.find() || [];
      const post = allPosts.find(p => p.slug === req.params.slug);
      
      if (!post) {
        return res.status(404).json({
          error: 'Not Found',
          message: 'Post not found'
        });
      }
      
      const { title, body, tags, status } = req.body;

      const validationErrors = validatePostInput(req.body, true);
      if (validationErrors.length > 0) {
        return res.status(400).json({
          error: 'Validation Error',
          message: validationErrors.join('; ')
        });
      }

      let processedBody = body || post.body;
      let newSlug = post.slug;

      if (body) {
        const uploadDir = path.join(__dirname, '../../public/uploads');
        try {
          processedBody = await imageProcessor.processImages(body, uploadDir);
        } catch (imgErr) {
          logger.warn('Image processing failed:', imgErr.message);
        }
        
        if (title && title !== post.title) {
          const inferred = metadata.inferMetadata(title, processedBody);
          newSlug = metadata.generateUniqueSlugFromList(allPosts, inferred.slug, post.id);
        }
      }
      
      const updatedPost = {
        ...post,
        title: title || post.title,
        slug: newSlug,
        body: processedBody,
        excerpt: body ? metadata.generateExcerpt(processedBody) : post.excerpt,
        keywords: body ? metadata.extractKeywords(title || post.title, processedBody) : post.keywords,
        meta_description: body ? metadata.generateMetaDescription(processedBody) : post.meta_description,
        reading_time: body ? metadata.calculateReadingTime(processedBody) : post.reading_time,
        status: status || post.status || 'draft',
        tags: tags !== undefined ? tags : post.tags,
        updated_at: new Date().toISOString()
      };
      
      await postsCollection.update(updatedPost.id, updatedPost);
      forceSavePost(updatedPost);

      ogImage.clearCache(post.slug);
      if (newSlug !== post.slug) {
        ogImage.clearCache(newSlug);
      }

      res.json(Post.fromDB(updatedPost).toApiJSON());
    } catch (err) {
      next(err);
    }
  }
  
  async executeDelete(slug) {
    const postsCollection = getCollection('posts');
    const allPosts = await postsCollection.find() || [];
    const post = allPosts.find(p => p.slug === slug);

    if (!post) {
      const error = new Error('Post not found');
      error.status = 404;
      throw error;
    }

    await postsCollection.delete(post.id);
    ogImage.clearCache(slug);

    return {
      message: 'Post deleted successfully',
      slug: slug
    };
  }

  async delete(req, res, next) {
    try {
      const postsCollection = getCollection('posts');
      const allPosts = await postsCollection.find() || [];
      const post = allPosts.find(p => p.slug === req.params.slug);

      if (!post) {
        return res.status(404).json({
          error: 'Not Found',
          message: 'Post not found'
        });
      }

      const token = await confirmationService.create('delete-post', { slug: req.params.slug });
      const confirmationUrl = `/api/confirm/${token}`;

      res.status(202).json({
        confirmation_required: true,
        message: 'This action requires confirmation. Please send a POST request to the confirmation_url to proceed.',
        confirmation_url: confirmationUrl
      });
    } catch (err) {
      next(err);
    }
  }
}

module.exports = new PostController();
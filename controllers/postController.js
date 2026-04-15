const { getCollection } = require('../config/database');
const Post = require('../models/post');
const metadata = require('../services/metadata');
const imageProcessor = require('../services/imageProcessor');
const { marked } = require('marked');
const path = require('path');

marked.setOptions({
  breaks: true,
  gfm: true
});

class PostController {
  async getAll(req, res, next) {
    try {
      const postsCollection = getCollection('posts');
      const postsData = await postsCollection.find();
      const posts = (postsData || [])
        .map(p => Post.fromDB(p).toApiJSON())
        .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
      
      const page = parseInt(req.query.page) || 1;
      const limit = parseInt(req.query.limit) || 10;
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
      
      if (!post) {
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
      
      if (!title || !body) {
        return res.status(400).json({
          error: 'Validation Error',
          message: 'Title and body are required'
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
            console.warn('Cover image download failed:', imgErr.message);
          }
        }
      } catch (imgErr) {
        console.warn('Image processing failed:', imgErr.message);
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
        status: status || 'unpublished',
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
      
      let processedBody = body || post.body;
      let newSlug = post.slug;
      
      if (body) {
        const uploadDir = path.join(__dirname, '../../public/uploads');
        try {
          processedBody = await imageProcessor.processImages(body, uploadDir);
        } catch (imgErr) {
          console.warn('Image processing failed:', imgErr.message);
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
        status: status || post.status || 'unpublished',
        tags: tags !== undefined ? tags : post.tags,
        updated_at: new Date().toISOString()
      };
      
      await postsCollection.update(updatedPost);
      
      res.json(Post.fromDB(updatedPost).toApiJSON());
    } catch (err) {
      next(err);
    }
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
      
      await postsCollection.delete(post.id);
      
      res.json({
        message: 'Post deleted successfully',
        slug: req.params.slug
      });
    } catch (err) {
      next(err);
    }
  }
}

module.exports = new PostController();
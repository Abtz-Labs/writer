const express = require('express');
const rateLimit = require('express-rate-limit');
const router = express.Router();
const postController = require('../controllers/postController');
const settingsController = require('../controllers/settingsController');
const confirmationService = require('../services/confirmation');
const authMiddleware = require('../middleware/auth');
const { optionalAuth } = require('../middleware/auth');

const onboardingLimiter = rateLimit({
  windowMs: 60 * 60 * 1000,
  max: 5,
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'Too many requests', message: 'Too many onboarding attempts. Please try again later.' },
});

function getApiDocs(req, res) {
  const baseUrl = `${req.protocol}://${req.get('host')}`;
  
  const docs = {
    name: 'Serif Blog API',
    version: '1.0.0',
    description: 'Self-discoverable RESTful API for blog management',
    
    authentication: {
      header: 'X-Auth-Token',
      description: 'All protected endpoints require this header',
      example: 'X-Auth-Token: your-auth-token-here',
      get_token: 'POST /api/onboarding'
    },
    
    endpoints: {
      public: [
        {
          method: 'GET',
          path: '/api',
          description: 'API documentation (this endpoint)',
          response: 'JSON API specification'
        },
        {
          method: 'GET',
          path: '/api/posts',
          description: 'List all posts with pagination',
          query: { page: 'Page number (default: 1)', limit: 'Posts per page (default: 10)' },
          response: '{ posts: [], pagination: {} }'
        },
        {
          method: 'GET',
          path: '/api/posts/:slug',
          description: 'Get single post by slug',
          response: 'Post object'
        },
        {
          method: 'GET',
          path: '/api/settings',
          description: 'Get blog settings',
          response: 'Settings object'
        },
        {
          method: 'POST',
          path: '/api/onboarding',
          description: 'Complete blog setup (first time only)',
          payload: { title: 'Blog title (required)', author: 'Author name (optional)', description: 'Blog description (optional)' },
          response: 'Settings with auth token'
        }
      ],
      protected: [
        {
          method: 'POST',
          path: '/api/posts',
          description: 'Create a new post',
          header: 'X-Auth-Token (required)',
          payload: { title: 'Post title (required)', body: 'Post content (required)', tags: 'Array of tags (optional)' },
          inference: {
            slug: 'Generated from title (lowercase, hyphens)',
            keywords: 'Extracted from title and body',
            meta_description: 'First 160 chars of body',
            reading_time: 'Calculated from word count'
          },
          response: 'Created post object'
        },
        {
          method: 'PUT',
          path: '/api/posts/:slug',
          description: 'Update an existing post',
          header: 'X-Auth-Token (required)',
          payload: { title: 'Post title (optional)', body: 'Post content (optional)', tags: 'Array of tags (optional)' },
          response: 'Updated post object'
        },
        {
          method: 'DELETE',
          path: '/api/posts/:slug',
          description: 'Delete a post (requires confirmation)',
          header: 'X-Auth-Token (required)',
          response: '{ confirmation_required: true, confirmation_url: "/api/confirm/..." }'
        },
        {
          method: 'PUT',
          path: '/api/settings',
          description: 'Update blog settings',
          header: 'X-Auth-Token (required)',
          payload: { title: 'Blog title (optional)', author: 'Author name (optional)', description: 'Blog description (optional)', custom_scripts: 'Custom HTML/scripts (optional)' },
          response: 'Updated settings object'
        },
        {
          method: 'POST',
          path: '/api/settings/rotate-token',
          description: 'Rotate the auth token (requires confirmation)',
          header: 'X-Auth-Token (required)',
          response: '{ confirmation_required: true, confirmation_url: "/api/confirm/..." }'
        },
        {
          method: 'POST',
          path: '/api/confirm/:token',
          description: 'Confirm a pending destructive action',
          header: 'X-Auth-Token (required)',
          response: 'Result of the confirmed action'
        }
      ]
    },
    
    example_usage: {
      create_post: {
        curl: `curl -X POST ${baseUrl}/api/posts \\
  -H "Content-Type: application/json" \\
  -H "X-Auth-Token: your-token-here" \\
  -d '{"title": "My First Post", "body": "Hello World!", "tags": ["intro"]}'`
      },
      list_posts: {
        curl: `curl ${baseUrl}/api/posts`
      }
    },
    
    links: {
      home: baseUrl + '/',
      onboarding: baseUrl + '/onboarding'
    }
  };
  
  res.json(docs);
}

router.get('/health', (req, res) => {
  res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

router.get('/', getApiDocs);

router.get('/posts', postController.getAll);
router.get('/posts/:slug', optionalAuth, postController.getBySlug);

router.post('/posts', authMiddleware, postController.create);
router.put('/posts/:slug', authMiddleware, postController.update);
router.delete('/posts/:slug', authMiddleware, postController.delete);

router.get('/settings', settingsController.get);

router.post('/onboarding', onboardingLimiter, settingsController.create);
router.put('/settings', authMiddleware, settingsController.update);
router.post('/settings/rotate-token', authMiddleware, settingsController.rotateToken);
router.put('/settings/credentials', authMiddleware, settingsController.updateCredentials);

router.post('/confirm/:token', authMiddleware, async (req, res, next) => {
  try {
    const pending = await confirmationService.get(req.params.token);
    if (!pending) {
      return res.status(410).json({
        error: 'Gone',
        message: 'Confirmation token expired or invalid'
      });
    }

    let result;
    switch (pending.action) {
      case 'delete-post':
        result = await postController.executeDelete(pending.data.slug);
        break;
      case 'rotate-token':
        result = await settingsController.executeRotateToken();
        break;
      default:
        return res.status(400).json({
          error: 'Bad Request',
          message: 'Unknown confirmation action'
        });
    }

    await confirmationService.delete(req.params.token);
    res.json(result);
  } catch (err) {
    next(err);
  }
});

module.exports = router;